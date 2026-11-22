<?php

namespace App\Http\Controllers;

use App\Services\ComposeAttachments;
use App\Services\MailBody;
use App\Services\MailExcerpt;
use App\Services\StalwartMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MailboxController extends Controller
{
    public function __construct(private StalwartMail $mail, private MailExcerpt $excerpt, private MailBody $body, private ComposeAttachments $uploads) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['folder' => 'required|in:imbox,sent,trash,feed,receipts,later,saved', 'q' => 'nullable|string|max:200', 'position' => 'nullable|integer|min:0|max:100000', 'section' => 'nullable|in:unread,seen']);
        $user = $request->user();
        try {
            $this->mail->ensureAccount($user);
            $boxes = collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role']], $user)['list'])->keyBy('role');
            $folder = $data['folder'];
            $filter = match ($folder) {
                'imbox', 'sent', 'trash' => ['inMailbox' => $boxes[$folder === 'imbox' ? 'inbox' : $folder]['id']],
                default => ['hasKeyword' => 'kioosk-'.$folder],
            };
            if (! empty($data['q'])) {
                $filter = ['text' => $data['q'], 'notKeyword' => '$draft'];
            } elseif ($folder === 'imbox' && isset($data['section'])) {
                $filter[$data['section'] === 'unread' ? 'notKeyword' : 'hasKeyword'] = '$seen';
            }
            if ($folder !== 'trash' && isset($boxes['trash'])) {
                $filter['inMailboxOtherThan'] = [$boxes['trash']['id']];
            }
            $ids = $this->mail->request('Email/query', [
                'filter' => $filter, 'position' => (int) ($data['position'] ?? 0), 'limit' => 35,
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            ], $user)['ids'];
            $items = $ids ? $this->mail->request('Email/get', ['ids' => $ids, 'properties' => [
                'id', 'subject', 'from', 'to', 'receivedAt', 'preview', 'keywords', 'mailboxIds',
            ]], $user)['list'] : [];

            return response()->json(['messages' => array_map(fn ($email) => $this->row($email, $boxes->get('sent')['id'] ?? ''), $items), 'more' => count($ids) === 35, 'draft' => $user->mail_draft]);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'دسترسی به صندوق ممکن نیست. دوباره تلاش کنید.'], 503);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->mail_account_id, 404);
        $email = $this->email($request, $id);

        $attachments = array_map(fn (array $file) => [
            'name' => $file['name'] ?? 'پیوست', 'size' => $file['size'] ?? 0,
            'url' => route('mail.attachment', ['id' => $id, 'blob' => $file['blobId']], false),
        ], $email['attachments'] ?? []);

        $text = $this->bodyParts($email, 'textBody');
        $html = $this->bodyParts($email, 'htmlBody');
        $fallback = $text !== '' ? $text : ($email['preview'] ?? '');

        return response()->json($this->row($email, '') + [
            'text' => $text !== '' ? $text : $this->body->plainText($this->body->render($fallback, $html)),
            'from' => $email['from'][0]['email'] ?? '',
            'html' => $this->body->render($fallback, $html),
            'replyAll' => array_values(array_unique(array_filter(array_merge([$email['replyTo'][0]['email'] ?? $email['from'][0]['email'] ?? null], array_column($email['to'] ?? [], 'email'), array_column($email['cc'] ?? [], 'email')), fn ($address) => $address && strcasecmp($address, $user->mailbox_address) !== 0))),
            'attachments' => $attachments,
        ]);
    }

    public function attachment(Request $request, string $id, string $blob): Response
    {
        $user = $request->user();
        abort_unless($user->mail_account_id, 404);
        $file = collect($this->email($request, $id)['attachments'] ?? [])->firstWhere('blobId', $blob);
        abort_unless($file, 404);
        abort_if(($file['size'] ?? 0) > 25 * 1024 * 1024, 413);

        $url = rtrim(config('stalwart.url'), '/').'/jmap/download/'
            .rawurlencode($user->mail_account_id).'/'.rawurlencode($blob).'/attachment?accept=application%2Foctet-stream';
        $result = Http::withBasicAuth($user->mailbox_address, $user->mail_secret)
            ->withoutRedirecting()->timeout(20)->get($url);
        abort_unless($result->successful(), 502);

        $name = str_replace(["\r", "\n", '/', '\\'], '_', $file['name'] ?? 'attachment');

        return response()->streamDownload(fn () => print ($result->body()), $name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function flag(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['flag' => 'required|in:read,later,saved,feed,receipts', 'value' => 'required|boolean']);
        $user = $request->user();
        abort_unless($user->mail_account_id, 404);
        $keyword = $data['flag'] === 'read' ? '$seen' : 'kioosk-'.$data['flag'];
        $result = $this->mail->request('Email/set', ['update' => [$id => ['keywords/'.$keyword => $data['value'] ?: null]]], $user);
        abort_if(isset($result['notUpdated'][$id]), 404);

        return response()->json(['ok' => true]);
    }

    public function organize(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:move,label,unlabel',
            'destination' => 'required_if:action,move|in:imbox,feed,receipts,trash',
            'label' => 'required_if:action,label,unlabel|string|max:25',
        ]);
        $user = $request->user();
        abort_unless($user->mail_account_id, 404);
        $email = $this->mail->request('Email/get', ['ids' => [$id], 'properties' => ['id']], $user)['list'];
        abort_if(empty($email), 404);

        if ($data['action'] === 'move') {
            $destination = $data['destination'];
            $role = match ($destination) {
                'imbox' => 'inbox',
                'trash' => 'trash',
                default => 'archive',
            };
            $boxes = collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role']], $user)['list'])->keyBy('role');
            abort_unless(isset($boxes[$role]), 422, 'پوشهٔ مقصد در دسترس نیست.');
            $patch = [
                'mailboxIds' => [$boxes[$role]['id'] => true],
                'keywords/kioosk-feed' => $destination === 'feed' ?: null,
                'keywords/kioosk-receipts' => $destination === 'receipts' ?: null,
            ];
        } else {
            $keyword = 'kioosk-label-'.bin2hex($data['label']);
            $patch = ['keywords/'.$keyword => $data['action'] === 'label' ?: null];
        }

        $result = $this->mail->request('Email/set', ['update' => [$id => $patch]], $user);
        abort_unless(array_key_exists($id, $result['updated'] ?? []), 422, 'تغییر نامه انجام نشد.');

        return response()->json(['ok' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:8192']]);
        abort_unless($request->user()->mail_account_id, 503);

        return response()->json($this->uploads->store($request->user(), $data['file']), 201);
    }

    public function removeUpload(Request $request, string $id): Response
    {
        $this->uploads->delete($request->user(), $id);

        return response()->noContent();
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => 'nullable|string|max:100000']);

        return response()->json(['html' => $this->body->render($data['body'] ?? '', '')]);
    }

    public function draft(Request $request): JsonResponse
    {
        $data = $this->composeInput($request, false);
        $data['files'] = $this->uploads->metadata($request->user(), $data['attachments'] ?? []);
        $request->user()->forceFill(['mail_draft' => $data])->save();

        return response()->json(['ok' => true]);
    }

    private function composeInput(Request $request, bool $sending): array
    {
        if (is_string($request->input('to'))) {
            $request->merge(['to' => $request->input('to') === '' ? [] : [$request->input('to')]]);
        }
        $required = $sending ? 'required' : 'nullable';
        $address = $sending ? 'required|email:rfc|max:254' : 'required|string|max:254';

        return $request->validate([
            'to' => $required.'|array|max:25', 'cc' => 'nullable|array|max:25', 'bcc' => 'nullable|array|max:25',
            'to.*' => $address, 'cc.*' => $address, 'bcc.*' => $address,
            'subject' => $required.'|string|max:300', 'body' => 'nullable|string|max:100000',
            'attachments' => 'nullable|array|max:10', 'attachments.*' => 'required|uuid|distinct',
            'reply' => 'nullable|string|max:128', 'forward' => 'nullable|string|max:128',
            'submission' => $sending ? 'required|uuid' : 'exclude',
        ], ['to.required' => 'دست‌کم یک گیرنده وارد کنید.', '*.email' => 'نشانی یکی از گیرندگان معتبر نیست.']);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $this->composeInput($request, true);
        abort_if(count($data['to']) + count($data['cc'] ?? []) + count($data['bcc'] ?? []) > 25, 422, 'حداکثر ۲۵ گیرنده مجاز است.');
        $user = $request->user();
        abort_unless($user->mail_account_id, 503);
        $key = 'mail-send:'.$user->id.':'.$data['submission'];

        return Cache::lock($key.':lock', 90)->block(5, function () use ($key, $request, $user, $data): JsonResponse {
            if (Cache::get($key) === 'sent') {
                return response()->json(['ok' => true]);
            }
            abort_if(Cache::has($key), 409, 'وضعیت ارسال نامشخص است. پوشهٔ ارسال‌شده‌ها را بررسی کنید.');
            $boxes = collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role']], $user)['list'])->keyBy('role');
            $identity = collect($this->mail->request('Identity/get', [], $user)['list'])->firstWhere('email', $user->mailbox_address);
            abort_unless($identity && isset($boxes['drafts'], $boxes['sent']), 503);
            $text = $data['body'] ?? '';
            $html = $this->body->render($text, '');
            $part = fn (string $field) => array_map(fn ($email) => ['email' => $email], array_values(array_unique($data[$field] ?? [])));
            $message = [
                'mailboxIds' => [$boxes['drafts']['id'] => true], 'keywords' => ['$draft' => true, '$seen' => true],
                'from' => [['email' => $user->mailbox_address, 'name' => $user->name]],
                'to' => $part('to'), 'cc' => $part('cc'), 'bcc' => $part('bcc'), 'subject' => $data['subject'],
                'textBody' => [['partId' => 'text', 'type' => 'text/plain']],
                'htmlBody' => [['partId' => 'html', 'type' => 'text/html']],
                'bodyValues' => ['text' => ['value' => $this->body->plainText($html)], 'html' => ['value' => '<div dir="auto">'.$html.'</div>']],
            ];
            if (! empty($data['reply'])) {
                $original = $this->email($request, $data['reply']);
                if (! empty($original['messageId'])) {
                    $message['inReplyTo'] = $original['messageId'];
                    $message['references'] = array_slice(array_merge($original['references'] ?? [], $original['messageId']), -20);
                }
            }
            $files = [];
            if (! empty($data['forward'])) {
                $original = $this->email($request, $data['forward']);
                $files = array_map(fn ($file) => array_intersect_key($file, array_flip(['blobId', 'type', 'name'])) + ['disposition' => 'attachment'], $original['attachments'] ?? []);
            }
            if (! empty($data['attachments'])) {
                $files = array_merge($files, $this->uploads->upload($user, $data['attachments']));
            }
            if ($files) {
                $message['attachments'] = $files;
            }
            $new = $this->mail->request('Email/set', ['create' => ['message' => $message]], $user);
            $id = $new['created']['message']['id'] ?? null;
            abort_unless($id, 422, 'نامه آمادهٔ ارسال نشد.');
            Cache::put($key, 'pending', now()->addDays(7));
            $result = $this->mail->request('EmailSubmission/set', [
                'create' => ['send' => ['identityId' => $identity['id'], 'emailId' => $id]],
                'onSuccessUpdateEmail' => ['#send' => ['mailboxIds' => [$boxes['sent']['id'] => true], 'keywords/$draft' => null]],
            ], $user);
            if (! isset($result['created']['send'])) {
                Cache::forget($key);
                abort(422, 'سرور ارسال را نپذیرفت.');
            }
            Cache::put($key, 'sent', now()->addDays(7));
            $user->forceFill(['mail_draft' => null])->save();
            foreach ($data['attachments'] ?? [] as $attachment) {
                $this->uploads->delete($user, $attachment);
            }

            return response()->json(['ok' => true]);
        });
    }

    private function email(Request $request, string $id): array
    {
        $result = $this->mail->request('Email/get', [
            'ids' => [$id], 'fetchTextBodyValues' => true, 'fetchHTMLBodyValues' => true, 'maxBodyValueBytes' => 160000,
        ], $request->user());

        return $result['list'][0] ?? abort(404);
    }

    private function bodyParts(array $email, string $type): string
    {
        $parts = array_map(fn ($part) => $email['bodyValues'][$part['partId']]['value'] ?? '', $email[$type] ?? []);

        return trim(implode("\n\n", $parts));
    }

    private function row(array $email, string $sent): array
    {
        $isSent = isset($email['mailboxIds'][$sent]);
        $address = ($email[$isSent ? 'to' : 'from'] ?? [])[0] ?? [];
        $sender = ($address['name'] ?? '') ?: ($address['email'] ?? '—');
        $flags = $email['keywords'] ?? [];

        return [
            'id' => $email['id'], 'subject' => ($email['subject'] ?? '') ?: 'بدون موضوع',
            'sender' => $sender, 'initial' => Str::substr($sender, 0, 1), 'email' => $address['email'] ?? '',
            'recipient' => implode('، ', array_column($email['to'] ?? [], 'email')),
            'body' => $this->excerpt->make($email['preview'] ?? ''), 'receivedAt' => $email['receivedAt'],
            'folder' => $isSent ? 'sent' : 'imbox', 'read' => isset($flags['$seen']),
            'labels' => array_values(array_map(
                fn ($key) => hex2bin(substr($key, 13)),
                array_filter(array_keys($flags), fn ($key) => preg_match('/^kioosk-label-(?:[0-9a-f]{2})+$/D', $key)),
            )),
            'later' => isset($flags['kioosk-later']), 'saved' => isset($flags['kioosk-saved']),
            'feed' => isset($flags['kioosk-feed']), 'receipts' => isset($flags['kioosk-receipts']),
            'replyTo' => ($email['replyTo'][0]['email'] ?? null) ?: ($address['email'] ?? ''),
        ];
    }
}
