<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class StalwartMail
{
    public function ensureAccount(User $user): void
    {
        if ($user->mail_account_id) {
            return;
        }

        Cache::lock('mail-provision:'.$user->id, 45)->block(5, function () use ($user): void {
            $user->refresh();
            if ($user->mail_account_id) {
                return;
            }
            $domain = config('auth.mailbox_domain');
            if (! str_ends_with((string) $user->mailbox_address, '@'.$domain)) {
                throw new RuntimeException('Mailbox domain mismatch.');
            }

            if (! $user->mail_secret) {
                $user->forceFill(['mail_secret' => Str::random(48)])->save();
            }

            $response = $this->request('x:Account/set', ['create' => ['mailbox' => [
                '@type' => 'User',
                'name' => Str::before($user->mailbox_address, '@'),
                'domainId' => config('stalwart.domain_id'),
                'credentials' => (object) ['0' => ['@type' => 'Password', 'secret' => $user->mail_secret]],
                'quotas' => ['maxDiskQuota' => 104857600],
            ]]]);

            // Discover with the mailbox credential, including after a previously accepted create.
            $session = Http::withBasicAuth($user->mailbox_address, $user->mail_secret)
                ->timeout(10)->get(rtrim(config('stalwart.url'), '/').'/.well-known/jmap');
            $account = $session->json('primaryAccounts.urn:ietf:params:jmap:mail');
            if (! $session->successful() || ! is_string($account)) {
                throw new RuntimeException('Mailbox provisioning failed: '.($response['notCreated']['mailbox']['type'] ?? 'session'));
            }
            $user->forceFill(['mail_account_id' => $account])->save();
        });
    }

    public function uploadSieve(User $user, string $script): string
    {
        $base = rtrim(config('stalwart.url'), '/');
        $http = Http::withBasicAuth($user->mailbox_address, $user->mail_secret)
            ->withoutRedirecting()->connectTimeout(4)->timeout(12);
        $session = $http->get($base.'/jmap/session');
        $template = $session->json('uploadUrl');
        if (! $session->successful() || ! is_string($template) || ! str_starts_with($template, $base.'/')) {
            throw new RuntimeException('Stalwart Sieve upload URL is unavailable.');
        }
        $url = str_replace('{accountId}', rawurlencode($user->mail_account_id), $template);
        $response = $http->withBody($script, 'application/sieve')->post($url);
        $blob = $response->json('blobId');
        if (! $response->successful() || ! is_string($blob)) {
            throw new RuntimeException('Stalwart Sieve upload failed: '.$response->status());
        }

        return $blob;
    }

    public function request(string $method, array $args, ?User $user = null): array
    {
        if ($user) {
            $args['accountId'] = $user->mail_account_id;
        }
        $capability = str_starts_with($method, 'x:') ? 'urn:stalwart:jmap' : 'urn:ietf:params:jmap:mail';
        $using = ['urn:ietf:params:jmap:core', $capability];
        if (str_starts_with($method, 'SieveScript/')) {
            $using[] = 'urn:ietf:params:jmap:sieve';
        }
        if (str_starts_with($method, 'EmailSubmission/') || str_starts_with($method, 'Identity/')) {
            $using[] = 'urn:ietf:params:jmap:submission';
        }

        try {
            $response = Http::withBasicAuth(
                $user?->mailbox_address ?? config('stalwart.username'),
                $user?->mail_secret ?? config('stalwart.password'),
            )->connectTimeout(4)->timeout(12)->post(rtrim(config('stalwart.url'), '/').'/jmap/', [
                'using' => $using,
                'methodCalls' => [[$method, $args, 'call']],
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Stalwart connection failed: '.$method, previous: $exception);
        }

        $result = $response->json('methodResponses.0');
        if (! $response->successful() || ($result[0] ?? null) !== $method) {
            throw new RuntimeException('Stalwart request failed: '.$method.' / '.$response->status().' / '.($result[1]['type'] ?? 'unknown'));
        }

        return $result[1];
    }
}
