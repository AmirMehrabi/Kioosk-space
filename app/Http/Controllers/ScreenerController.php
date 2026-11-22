<?php

namespace App\Http\Controllers;

use App\Services\MailBody;
use App\Services\SenderScreening;
use App\Services\StalwartMail;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ScreenerController extends Controller
{
    public function __construct(private SenderScreening $screening) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->screening_enabled, 404);

        try {
            if ($request->boolean('history')) {
                return response()->json(['history' => $this->screening->history($user)]);
            }
            $senders = $this->screening->pending($user);

            return response()->json($request->boolean('count')
                ? ['count' => count($senders)]
                : ['senders' => $senders, 'count' => count($senders)]);
        } catch (RuntimeException $exception) {
            report($exception);
            abort(503, 'دسترسی به بررسی فرستنده‌ها ممکن نیست. دوباره تلاش کنید.');
        }
    }

    public function preview(Request $request, string $id, StalwartMail $mail, MailBody $body): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->screening_enabled, 404);

        try {
            $email = $mail->request('Email/get', [
                'ids' => [$id],
                'properties' => ['mailboxIds', 'to', 'cc', 'receivedAt', 'textBody', 'htmlBody', 'bodyValues', 'preview'],
                'fetchTextBodyValues' => true, 'fetchHTMLBodyValues' => true, 'maxBodyValueBytes' => 160000,
            ], $user)['list'][0] ?? [];
        } catch (RuntimeException $exception) {
            report($exception);
            abort(503, 'پیش‌نمایش در دسترس نیست. دوباره تلاش کنید.');
        }
        abort_unless($this->screening->isHeld($user, $email), 404);

        $parts = fn (string $name) => trim(implode("\n\n", array_map(
            fn (array $part) => $email['bodyValues'][$part['partId']]['value'] ?? '', $email[$name] ?? []
        )));
        $text = $parts('textBody') ?: $body->plainText($body->richText($parts('htmlBody')));

        return response()->json([
            'text' => $text ?: ($email['preview'] ?? ''),
            'to' => array_column($email['to'] ?? [], 'email'),
            'cc' => array_column($email['cc'] ?? [], 'email'),
            'receivedAt' => $email['receivedAt'],
        ]);
    }

    public function decide(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->screening_enabled, 404);
        $input = $request->validate([
            'address' => 'required|string|max:320',
            'destination' => 'present|nullable|in:imbox,feed,receipts,out',
            'revision' => 'required_if:destination,null|nullable|uuid',
        ]);
        $address = SenderScreening::normalize($input['address']);
        abort_unless($address, 422, 'نشانی فرستنده معتبر نیست.');
        abort_if($input['destination'] === null && empty($input['revision']), 422, 'این تصمیم قابل بازگردانی نیست.');

        try {
            return response()->json($this->screening->decide($user, $address, $input['destination'], $input['revision'] ?? null));
        } catch (HttpException $exception) {
            throw $exception;
        } catch (RuntimeException|LockTimeoutException $exception) {
            report($exception);
            abort(503, 'ثبت تصمیم ممکن نیست. دوباره تلاش کنید.');
        }
    }
}
