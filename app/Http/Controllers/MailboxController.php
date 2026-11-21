<?php

namespace App\Http\Controllers;

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
    public function __construct(private StalwartMail $mail, private MailExcerpt $excerpt) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['folder' => 'required|in:imbox,sent,feed,receipts,later,saved', 'q' => 'nullable|string|max:200', 'position' => 'nullable|integer|min:0|max:100000']);
        $user = $request->user();
        try {
            $this->mail->ensureAccount($user);
            $boxes = collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role']], $user)['list'])->keyBy('role');
            $folder = $data['folder'];
            $filter = match ($folder) {
                'imbox', 'sent' => ['inMailbox' => $boxes[$folder === 'imbox' ? 'inbox' : 'sent']['id']],
                default => ['hasKeyword' => 'kioosk-'.$folder],
            };
            if (! empty($data['q'])) {
                $filter = ['text' => $data['q'], 'notKeyword' => '$draft'];
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

        return response()->json($this->row($email, '') + ['text' => $this->plainBody($email), 'attachments' => $attachments]);
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

    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => 'nullable|string|max:254', 'subject' => 'nullable|string|max:300', 'body' => 'nullable|string|max:100000']);
        $request->user()->forceFill(['mail_draft' => $data])->save();

        return response()->json(['ok' => true]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => 'required|email:rfc|max:254', 'subject' => 'required|string|max:300', 'body' => 'required|string|max:100000',
            'submission' => 'required|uuid', 'reply' => 'nullable|string|max:128',
        ]);
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
            $message = [
                'mailboxIds' => [$boxes['drafts']['id'] => true], 'keywords' => ['$draft' => true, '$seen' => true],
                'from' => [['email' => $user->mailbox_address, 'name' => $user->name]],
                'to' => [['email' => $data['to']]], 'subject' => $data['subject'],
                'textBody' => [['partId' => 'text', 'type' => 'text/plain']], 'bodyValues' => ['text' => ['value' => $data['body']]],
            ];
            if (! empty($data['reply'])) {
                $original = $this->email($request, $data['reply']);
                if (! empty($original['messageId'])) {
                    $message['inReplyTo'] = $original['messageId'];
                    $message['references'] = array_slice(array_merge($original['references'] ?? [], $original['messageId']), -20);
                }
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

            return response()->json(['ok' => true]);
        });
    }

    private function email(Request $request, string $id): array
    {
        $result = $this->mail->request('Email/get', [
            'ids' => [$id], 'fetchTextBodyValues' => true, 'maxBodyValueBytes' => 160000,
        ], $request->user());

        return $result['list'][0] ?? abort(404);
    }

    private function plainBody(array $email): string
    {
        $parts = array_map(fn ($part) => $email['bodyValues'][$part['partId']]['value'] ?? '', $email['textBody'] ?? []);

        return trim(implode("\n\n", $parts)) ?: ($email['preview'] ?? '');
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
            'later' => isset($flags['kioosk-later']), 'saved' => isset($flags['kioosk-saved']),
            'feed' => isset($flags['kioosk-feed']), 'receipts' => isset($flags['kioosk-receipts']),
            'replyTo' => ($email['replyTo'][0]['email'] ?? null) ?: ($address['email'] ?? ''),
        ];
    }
}
