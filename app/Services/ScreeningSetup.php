<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ScreeningSetup
{
    private const NAME = 'Kioosk Screener';

    private const SCRIPT = <<<'SIEVE'
        require ["fileinto", "spamtest", "relational", "comparator-i;ascii-numeric"];
        if spamtest :value "ge" :comparator "i;ascii-numeric" "5" {
            stop;
        }
        fileinto "Kioosk Screener";
        SIEVE;

    public function __construct(private StalwartMail $mail, private SenderScreening $screening) {}

    public function enable(User $user): void
    {
        $this->mail->ensureAccount($user);

        Cache::lock('screener:'.$user->id, 600)->block(5, function () use ($user): void {
            $user->refresh();
            $scripts = $this->mail->request('SieveScript/get', [], $user);
            $existing = collect($scripts['list'])->firstWhere('name', self::NAME);
            if (collect($scripts['list'])->contains(fn (array $script) => $script['isActive'] && $script['name'] !== self::NAME)) {
                throw new RuntimeException('Another Sieve script is active. Screening cannot replace it.');
            }

            if (! $user->screening_mailbox_id) {
                $all = $this->mail->request('Mailbox/get', ['properties' => ['id', 'name', 'parentId']], $user);
                $folder = collect($all['list'])->first(fn (array $box) => $box['name'] === self::NAME && $box['parentId'] === null);
                if (! $folder) {
                    $result = $this->mail->request('Mailbox/set', ['create' => ['screener' => ['name' => self::NAME]]], $user);
                    $folder = $result['created']['screener'] ?? throw new RuntimeException('Unable to create the screening mailbox.');
                }
                $user->forceFill(['screening_mailbox_id' => $folder['id']])->save();
            }

            $boxes = $this->screening->boxes($user);
            if (! isset($boxes['inbox'])) {
                throw new RuntimeException('Screening requires an Inbox mailbox.');
            }
            if (! isset($boxes['archive'])) {
                $result = $this->mail->request('Mailbox/set', [
                    'create' => ['archive' => ['name' => 'Archive', 'role' => 'archive']],
                ], $user);
                $archive = $result['created']['archive'] ?? throw new RuntimeException('Unable to provision an Archive mailbox for Feed and Receipts.');
                $boxes->put('archive', $archive);
            }
            if (! $user->screening_enabled) {
                $this->screening->seedCorrespondents($user, $boxes);
            }

            if (! ($existing['isActive'] ?? false)) {
                $script = ['blobId' => $this->mail->uploadSieve($user, self::SCRIPT)];
                $args = $existing
                    ? ['update' => [$existing['id'] => $script], 'onSuccessActivateScript' => $existing['id']]
                    : ['create' => ['screener' => $script + ['name' => self::NAME]], 'onSuccessActivateScript' => '#screener'];
                $result = $this->mail->request('SieveScript/set', $args + ['ifInState' => $scripts['state']], $user);
                $active = $existing ? ($result['updated'][$existing['id']] ?? []) : ($result['created']['screener'] ?? []);
                if (! ($active['isActive'] ?? false)) {
                    throw new RuntimeException('Stalwart did not activate the screening script.');
                }
            }
            $user->forceFill(['screening_enabled' => true])->save();
        });
    }
}
