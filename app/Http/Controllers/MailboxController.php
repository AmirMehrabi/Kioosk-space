<?php

namespace App\Http\Controllers;

use App\Models\MailDraft;
use App\Models\User;
use App\Services\ComposeAttachments;
use App\Services\MailBody;
use App\Services\MailDestination;
use App\Services\MailExcerpt;
use App\Services\SenderScreening;
use App\Services\StalwartMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MailboxController extends Controller
{
    public function __construct(private StalwartMail $mail, private MailExcerpt $excerpt, private MailBody $body, private ComposeAttachments $uploads, private SenderScreening $screening, private MailDestination $destination) {}

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
            if ($user->screening_mailbox_id) {
                $filter = ['operator' => 'AND', 'conditions' => [
                    $filter,
                    ['operator' => 'NOT', 'conditions' => [['inMailbox' => $user->screening_mailbox_id]]],
                ]];
            }
            $ids = $this->mail->request('Email/query', [
                'filter' => $filter, 'position' => (int) ($data['position'] ?? 0), 'limit' => 35,
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            ], $user)['ids'];
            $items = $ids ? $this->mail->request('Email/get', ['ids' => $ids, 'properties' => [
                'id', 'subject', 'from', 'to', 'receivedAt', 'preview', 'keywords', 'mailboxIds',
            ]], $user)['list'] : [];

            return response()->json(['messages' => array_map(fn ($email) => $this->row($email, $boxes->get('sent')['id'] ?? ''), $items), 'more' => count($ids) === 35]);
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
            'replyAll' => $this->replyRecipients($email, $user),
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
        if ($user->screening_mailbox_id) {
            $this->assertVisible($user, $id);
        }
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
        $this->assertVisible($user, $id);

        if ($data['action'] === 'move') {
            $boxes = collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role']], $user)['list'])->keyBy('role');
            try {
                $patch = $this->destination->patch($boxes, $data['destination']);
            } catch (RuntimeException) {
                abort(422, 'پوشهٔ مقصد در دسترس نیست.');
            }
        } else {
            $keyword = 'kioosk-label-'.bin2hex($data['label']);
            $patch = ['keywords/'.$keyword => $data['action'] === 'label' ?: null];
        }

        $result = $this->mail->request('Email/set', ['update' => [$id => $patch]], $user);
        abort_unless(array_key_exists($id, $result['updated'] ?? []), 422, 'تغییر نامه انجام نشد.');

        return response()->json(['ok' => true]);
    }

    public function openDraft(Request $request): JsonResponse
    {
        $input = $request->validate([
            'reply' => 'nullable|string|max:128|prohibits:forward,recover',
            'forward' => 'nullable|string|max:128|prohibits:reply,recover',
            'all' => 'sometimes|boolean',
            'recover' => 'sometimes|boolean',
        ]);
        $user = $request->user();
        $reply = $input['reply'] ?? null;
        $forward = $input['forward'] ?? null;
        $recover = $request->boolean('recover');
        $target = $reply ?? $forward;
        if ($target !== null) {
            $this->mail->ensureAccount($user);
        }
        $original = $target === null ? null : $this->email($request, $target);
        $context = match (true) {
            $reply !== null => $this->replyContext($original),
            $forward !== null => 'forward:'.hash('sha1', $forward),
            $recover => 'recovered',
            default => 'new',
        };

        $legacyReply = $user->mail_draft['reply'] ?? null;
        $legacyOriginal = $reply === $legacyReply ? $original : null;
        if ($reply !== null && $legacyReply && $legacyReply !== $reply) {
            $legacyOriginal = $this->findEmail($request, $legacyReply);
        }

        return DB::transaction(function () use ($user, $input, $original, $legacyOriginal, $context, $reply, $forward, $recover): JsonResponse {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $draft = MailDraft::query()->where('user_id', $user->id)->where('context', $context)->first();
            $legacy = $owner->mail_draft;

            if ($draft === null) {
                $legacyMatches = $legacy && match (true) {
                    $reply !== null => $legacyOriginal && ($legacy['reply'] ?? null) === $legacyOriginal['id']
                        && $this->replyContext($legacyOriginal) === $context,
                    $forward !== null => ($legacy['forward'] ?? null) === $forward,
                    $recover => true,
                    default => empty($legacy['reply']) && empty($legacy['forward']),
                };
                $content = $this->initialDraft($user, $original, $reply, $forward, (bool) ($input['all'] ?? false));
                if ($legacyMatches) {
                    $content = array_replace($content, array_intersect_key($legacy, array_flip(['to', 'cc', 'bcc', 'subject', 'attachments'])));
                    foreach (['to', 'cc', 'bcc'] as $field) {
                        $content[$field] = array_values(array_filter((array) $content[$field]));
                    }
                    $content['bodyHtml'] = $this->body->render($legacy['body'] ?? '', '');
                    if ($reply !== null) {
                        $content['reply'] = $legacyOriginal['id'];
                        $content['quote'] = $this->replyQuote($legacyOriginal);
                    }
                    $owner->forceFill(['mail_draft' => null])->save();
                }
                $draft = MailDraft::query()->create(['user_id' => $user->id, 'context' => $context, 'content' => $content]);
            }

            $content = $draft->content;
            $files = $this->uploads->available($user, $content['attachments'] ?? [], $draft->id, $content['attachments'] ?? []);
            $content['attachments'] = array_column($files, 'id');

            return response()->json([
                'id' => $draft->id, 'revision' => $draft->revision,
                'draft' => $content + ['files' => $files],
                'hasLegacyDraft' => (bool) $owner->mail_draft,
            ])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function upload(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:8192']]);

        return Cache::lock('mail-draft:'.$id, 90)->block(5, function () use ($request, $id, $data): JsonResponse {
            $draft = $this->ownedDraft($request, $id);
            $user = $request->user();
            $this->mail->ensureAccount($user);

            return response()->json($this->uploads->store($user, $data['file'], $id, $draft->content['attachments'] ?? []), 201);
        });
    }

    public function draft(Request $request, string $id): JsonResponse
    {
        $data = $this->composeInput($request);

        return Cache::lock('mail-draft:'.$id, 90)->block(5, function () use ($request, $id, $data): JsonResponse {
            $draft = $this->ownedDraft($request, $id, (int) $data['revision']);
            $user = $request->user();
            $previous = $draft->content['attachments'] ?? [];
            $files = $this->uploads->metadata($user, $data['attachments'] ?? [], $id, $previous);
            abort_if(array_sum(array_column($files, 'size')) > 16 * 1024 * 1024, 422, 'مجموع پیوست‌ها حداکثر ۱۶ مگابایت.');
            $data['bodyHtml'] = $this->body->richText($data['bodyHtml'] ?? '');
            unset($data['revision']);
            $draft->content = array_replace($draft->content, $data);
            $draft->revision++;
            $draft->save();

            foreach (array_diff($previous, $data['attachments'] ?? []) as $attachment) {
                $this->uploads->delete($user, $attachment);
            }

            return response()->json(['revision' => $draft->revision]);
        });
    }

    public function discardDraft(Request $request, string $id): Response
    {
        $revision = $request->validate(['revision' => 'required|integer|min:0'])['revision'];

        return Cache::lock('mail-draft:'.$id, 90)->block(5, function () use ($request, $id, $revision): Response {
            $draft = $this->ownedDraft($request, $id, (int) $revision);
            $this->deleteDraft($request->user(), $draft);

            return response()->noContent();
        });
    }

    private function ownedDraft(Request $request, string $id, ?int $revision = null): MailDraft
    {
        $draft = MailDraft::query()->where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($revision !== null && $draft->revision !== $revision, 409, 'پیش‌نویس در جای دیگری تغییر کرده است. برای بارگذاری دوباره، صفحه را تازه کنید.');

        return $draft;
    }

    private function deleteDraft(User $user, MailDraft $draft): void
    {
        $draft->delete();
        $this->uploads->discard($user, $draft->id, $draft->content['attachments'] ?? []);
    }

    private function composeInput(Request $request): array
    {
        return $request->validate([
            'revision' => 'required|integer|min:0',
            'to' => 'present|array|max:25', 'cc' => 'present|array|max:25', 'bcc' => 'present|array|max:25',
            'to.*' => 'required|string|max:254', 'cc.*' => 'required|string|max:254', 'bcc.*' => 'required|string|max:254',
            'subject' => 'present|nullable|string|max:300', 'bodyHtml' => 'present|nullable|string|max:200000',
            'attachments' => 'present|array|max:10', 'attachments.*' => 'required|uuid|distinct',
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $input = $request->validate(['draft' => 'required|uuid', 'revision' => 'required|integer|min:0']);
        $user = $request->user();
        $id = $input['draft'];
        $key = 'mail-send:'.$user->id.':'.$id;

        return Cache::lock('mail-draft:'.$id, 90)->block(5, function () use ($key, $request, $user, $input, $id): JsonResponse {
            if (Cache::get($key) === 'sent') {
                return response()->json(['ok' => true]);
            }
            abort_if(Cache::has($key), 409, 'وضعیت ارسال نامشخص است. پوشهٔ ارسال‌شده‌ها را بررسی کنید.');
            $draft = $this->ownedDraft($request, $id, (int) $input['revision']);
            $data = $draft->content;
            validator($data, [
                'to' => 'required|array', 'to.*' => 'required|email:rfc',
                'cc.*' => 'required|email:rfc', 'bcc.*' => 'required|email:rfc',
                'subject' => 'required|string|max:300',
            ], ['to.required' => 'دست‌کم یک گیرنده وارد کنید.', '*.email' => 'نشانی یکی از گیرندگان معتبر نیست.'])->validate();
            abort_if(count($data['to']) + count($data['cc'] ?? []) + count($data['bcc'] ?? []) > 25, 422, 'حداکثر ۲۵ گیرنده مجاز است.');
            $this->mail->ensureAccount($user);
            $boxes = collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role']], $user)['list'])->keyBy('role');
            $identity = collect($this->mail->request('Identity/get', [], $user)['list'])->firstWhere('email', $user->mailbox_address);
            abort_unless($identity && isset($boxes['drafts'], $boxes['sent']), 503);
            $html = $this->body->richText($data['bodyHtml'] ?? '');
            $text = $this->body->plainText($html);
            if ($quote = $data['quote'] ?? null) {
                $html .= '<p dir="auto">'.e($quote['attribution']).'</p>'
                    .'<blockquote type="cite" style="margin:0 0 0 0.8em;border-inline-start:2px solid #b8b8b8;padding-inline-start:0.8em">'.$quote['html'].'</blockquote>';
                $quotedText = implode("\n", array_map(fn (string $line): string => '> '.$line, explode("\n", $quote['text'])));
                $text = ltrim($text."\n\n".$quote['attribution']."\n".$quotedText);
            }
            $part = fn (string $field) => array_map(fn ($email) => ['email' => $email], array_values(array_unique($data[$field] ?? [])));
            $message = [
                'mailboxIds' => [$boxes['drafts']['id'] => true], 'keywords' => ['$draft' => true, '$seen' => true],
                'from' => [['email' => $user->mailbox_address, 'name' => $user->name]],
                'to' => $part('to'), 'cc' => $part('cc'), 'bcc' => $part('bcc'), 'subject' => $data['subject'],
                'textBody' => [['partId' => 'text', 'type' => 'text/plain']],
                'htmlBody' => [['partId' => 'html', 'type' => 'text/html']],
                'bodyValues' => ['text' => ['value' => $text], 'html' => ['value' => '<div dir="auto">'.$html.'</div>']],
            ];
            if (! empty($data['quote']['messageId'])) {
                $message['inReplyTo'] = $data['quote']['messageId'];
                $message['references'] = array_slice(array_merge($data['quote']['references'], $message['inReplyTo']), -20);
            }
            $files = [];
            if (! empty($data['forward'])) {
                $original = $this->email($request, $data['forward']);
                $files = array_map(fn ($file) => array_intersect_key($file, array_flip(['blobId', 'type', 'name'])) + ['disposition' => 'attachment'], $original['attachments'] ?? []);
            }
            if (! empty($data['attachments'])) {
                $files = array_merge($files, $this->uploads->upload($user, $data['attachments'], $draft->id));
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
            $this->deleteDraft($user, $draft);

            return response()->json(['ok' => true]);
        });
    }

    private function replyContext(array $original): string
    {
        return 'reply:'.hash('sha1', $original['threadId'] ?? $original['id']);
    }

    private function initialDraft(User $user, ?array $original, ?string $reply, ?string $forward, bool $all): array
    {
        $content = ['to' => [], 'cc' => [], 'bcc' => [], 'subject' => '', 'bodyHtml' => '', 'attachments' => [],
            'reply' => $reply, 'forward' => $forward];

        if ($reply !== null) {
            $recipient = $original['replyTo'][0]['email'] ?? $original['from'][0]['email'] ?? '';
            $content['to'] = $all ? $this->replyRecipients($original, $user) : array_values(array_filter([$recipient]));
            $subject = $original['subject'] ?? '';
            $content['subject'] = Str::substr(preg_match('/^(?:re|پاسخ):/iu', $subject) ? $subject : 'Re: '.$subject, 0, 300);
            $content['quote'] = $this->replyQuote($original);
        }

        if ($forward !== null) {
            $quote = $this->replyQuote($original);
            $content['subject'] = Str::substr('بازارسال: '.($original['subject'] ?? ''), 0, 300);
            $content['bodyHtml'] = '<p><br></p><p>'.e($quote['attribution']).'</p><blockquote>'.$quote['html'].'</blockquote>';
        }

        return $content;
    }

    private function replyQuote(array $original): array
    {
        $html = $this->body->richText($this->bodyParts($original, 'htmlBody'));
        $text = $this->bodyParts($original, 'textBody');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($html === '') {
            $text = $text !== '' ? $text : ($original['preview'] ?? '');
            $html = '<div dir="auto">'.nl2br(e($text), false).'</div>';
        }
        $sender = ($original['from'][0]['name'] ?? '') ?: ($original['from'][0]['email'] ?? '—');
        $date = $original['sentAt'] ?? $original['receivedAt'] ?? null;
        $attribution = $date ? Carbon::parse($date)->locale('fa')->isoFormat('LLL').'، '.$sender.' نوشت:' : $sender.' نوشت:';

        return [
            'attribution' => $attribution, 'html' => $html,
            'text' => $text !== '' ? $text : $this->body->plainText($html),
            'messageId' => $original['messageId'] ?? [], 'references' => $original['references'] ?? [],
        ];
    }

    private function replyRecipients(array $email, User $user): array
    {
        $addresses = array_merge(
            [$email['replyTo'][0]['email'] ?? $email['from'][0]['email'] ?? null],
            array_column($email['to'] ?? [], 'email'),
            array_column($email['cc'] ?? [], 'email'),
        );

        return array_values(collect($addresses)
            ->filter(fn ($address) => $address && strcasecmp($address, $user->mailbox_address) !== 0)
            ->unique(fn (string $address) => strtolower($address))->all());
    }

    private function email(Request $request, string $id): array
    {
        return $this->findEmail($request, $id) ?? abort(404);
    }

    private function assertVisible(User $user, string $id): void
    {
        $properties = $user->screening_mailbox_id ? ['id', 'mailboxIds'] : ['id'];
        $email = $this->mail->request('Email/get', ['ids' => [$id], 'properties' => $properties], $user)['list'][0] ?? null;
        abort_if($email === null || $this->screening->isHeld($user, $email), 404);
    }

    private function findEmail(Request $request, string $id): ?array
    {
        $result = $this->mail->request('Email/get', [
            'ids' => [$id], 'fetchTextBodyValues' => true, 'fetchHTMLBodyValues' => true, 'maxBodyValueBytes' => 160000,
        ], $request->user());

        $email = $result['list'][0] ?? null;

        return $email && ! $this->screening->isHeld($request->user(), $email) ? $email : null;
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
