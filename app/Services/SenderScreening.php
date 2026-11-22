<?php

namespace App\Services;

use App\Models\SenderDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class SenderScreening
{
    private const REJECTED = 'kioosk-screened-out';

    private const RELEASED = 'kioosk-screened-';

    private const METADATA = ['id', 'from', 'subject', 'preview', 'receivedAt'];

    public function __construct(private StalwartMail $mail, private MailDestination $destination, private MailExcerpt $excerpt) {}

    public static function normalize(string $address): ?string
    {
        $address = trim($address);

        return mb_strlen($address) <= 320 && preg_match('/\A[^@\s\p{C}]+@[^@\s\p{C}]+\z/u', $address) ? mb_strtolower($address) : null;
    }

    public function sender(array $email): ?string
    {
        return count($email['from'] ?? []) === 1 ? self::normalize($email['from'][0]['email'] ?? '') : null;
    }

    public function isHeld(User $user, array $email): bool
    {
        return $user->screening_mailbox_id && isset($email['mailboxIds'][$user->screening_mailbox_id]);
    }

    public function pending(User $user): array
    {
        $decided = $this->decisions($user)->whereNotNull('destination')->pluck('destination', 'address');
        $groups = [];

        foreach ($this->messages($user, ['inMailbox' => $user->screening_mailbox_id, 'notKeyword' => self::REJECTED], self::METADATA) as $email) {
            $address = $this->sender($email);
            if ($address === null || $decided->has($address)) {
                continue;
            }
            $message = [
                'id' => $email['id'],
                'subject' => ($email['subject'] ?? '') ?: 'بدون موضوع',
                'excerpt' => $this->excerpt->make($email['preview'] ?? ''),
                'receivedAt' => $email['receivedAt'],
            ];
            $groups[$address] ??= [
                'address' => $address,
                'name' => ($email['from'][0]['name'] ?? '') ?: $address,
                'messages' => [],
            ];
            $groups[$address]['messages'][] = $message;
        }

        return array_values($groups);
    }

    public function history(User $user): array
    {
        return $this->decisions($user)->whereNotNull('destination')
            ->orderByRaw('case when reviewed_at is null then 1 else 0 end')->latest('reviewed_at')->orderBy('address')->get()->map(fn (SenderDecision $decision) => $this->summary($decision))->all();
    }

    public function decide(User $user, string $address, ?string $destination, ?string $revision): array
    {
        return Cache::lock('screener:'.$user->id, 600)->block(5, function () use ($user, $address, $destination, $revision): array {
            $decision = $this->decisions($user)->where('address', $address)->first();
            $currentRevision = $decision?->destination === null ? null : $decision->revision;
            abort_if($revision !== $currentRevision, 409, 'تصمیم این فرستنده تغییر کرده است.');

            if (! $decision || $decision->destination === null) {
                abort_unless($this->heldFrom($user, $address)->isNotEmpty(), 404);
            }

            $decision ??= new SenderDecision(['user_id' => $user->id, 'address' => $address]);
            if ($decision->needs_sync) {
                $this->syncDecision($user, $decision, $this->boxes($user));
            }

            $previous = $decision->destination;
            $undo = $destination === null;
            $decision->fill([
                'destination' => $undo ? $decision->previous_destination : $destination,
                'previous_destination' => $previous,
                'return_revision' => $undo && $previous && $previous !== 'out' ? $decision->revision : null,
                'return_destination' => $undo ? $previous : null,
                'revision' => (string) Str::uuid(),
                'needs_sync' => true,
                'reviewed_at' => now(),
            ])->save();

            try {
                $this->syncDecision($user, $decision, $this->boxes($user));
            } catch (RuntimeException $exception) {
                report($exception);
            }

            return $this->summary($decision) + ['syncing' => $decision->needs_sync];
        });
    }

    public function sync(User $user): void
    {
        Cache::lock('screener:'.$user->id, 600)->get(function () use ($user): void {
            $boxes = $this->boxes($user);
            foreach ($this->decisions($user)->where('needs_sync', true)->cursor() as $decision) {
                $this->syncDecision($user, $decision, $boxes);
            }

            $decisions = $this->decisions($user)->whereNotNull('destination')->get()->keyBy('address');
            $updates = [];
            $messages = $this->messages($user, [
                'inMailbox' => $user->screening_mailbox_id,
                'notKeyword' => self::REJECTED,
            ], ['id', 'from']);

            foreach ($messages as $email) {
                $address = $this->sender($email);
                $decision = $decisions->get($address);
                if ($decision || $address === null) {
                    $updates[$email['id']] = $this->deliveryPatch($boxes, $decision);
                }
            }
            $this->update($user, $updates);
        });
    }

    public function boxes(User $user): Collection
    {
        return collect($this->mail->request('Mailbox/get', ['properties' => ['id', 'role', 'name']], $user)['list'])->keyBy('role');
    }

    public function seedCorrespondents(User $user, Collection $boxes): void
    {
        foreach (['inbox' => 'from', 'archive' => 'from', 'sent' => 'to'] as $role => $property) {
            if (! isset($boxes[$role])) {
                continue;
            }
            foreach ($this->messages($user, ['inMailbox' => $boxes[$role]['id']], ['id', $property, 'keywords']) as $email) {
                foreach ($email[$property] ?? [] as $contact) {
                    $address = self::normalize($contact['email'] ?? '');
                    if ($address === null || ($property === 'from' && $address !== $this->sender($email))) {
                        continue;
                    }
                    $destination = match (true) {
                        isset($email['keywords']['kioosk-feed']) => 'feed',
                        isset($email['keywords']['kioosk-receipts']) => 'receipts',
                        default => 'imbox',
                    };
                    SenderDecision::query()->firstOrCreate(['user_id' => $user->id, 'address' => $address], [
                        'destination' => $destination, 'revision' => (string) Str::uuid(),
                    ]);
                }
            }
        }
    }

    private function decisions(User $user): Builder
    {
        return SenderDecision::query()->where('user_id', $user->id);
    }

    private function summary(SenderDecision $decision): array
    {
        return [
            'address' => $decision->address,
            'destination' => $decision->destination,
            'revision' => $decision->revision,
            'updatedAt' => $decision->reviewed_at?->toIso8601String(),
        ];
    }

    private function syncDecision(User $user, SenderDecision $decision, Collection $boxes): void
    {
        if ($decision->return_revision) {
            $original = $this->destination->patch($boxes, $decision->return_destination);
            $keyword = self::RELEASED.$decision->return_revision;
            $updates = [];
            foreach ($this->messages($user, ['hasKeyword' => $keyword], ['id', 'mailboxIds', 'keywords']) as $email) {
                if ($email['mailboxIds'] === $original['mailboxIds']
                    && isset($email['keywords']['kioosk-feed']) === ($decision->return_destination === 'feed')
                    && isset($email['keywords']['kioosk-receipts']) === ($decision->return_destination === 'receipts')) {
                    $updates[$email['id']] = [
                        'mailboxIds' => [$user->screening_mailbox_id => true],
                        'keywords/'.$keyword => null,
                        'keywords/kioosk-feed' => null,
                        'keywords/kioosk-receipts' => null,
                    ];
                }
            }
            $this->update($user, $updates);
        }

        $updates = [];
        $patch = $this->deliveryPatch($boxes, $decision);
        foreach ($this->heldFrom($user, $decision->address) as $email) {
            $updates[$email['id']] = $patch;
        }
        $this->update($user, $updates);
        $decision->update(['return_revision' => null, 'return_destination' => null, 'needs_sync' => false]);
    }

    private function deliveryPatch(Collection $boxes, ?SenderDecision $decision): array
    {
        $destination = $decision?->destination;
        if ($destination === null || $destination === 'out') {
            return ['keywords/'.self::REJECTED => ($decision === null || $destination === 'out') ?: null];
        }

        return $this->destination->patch($boxes, $destination) + [
            'keywords/'.self::REJECTED => null,
            'keywords/'.self::RELEASED.$decision->revision => true,
        ];
    }

    private function heldFrom(User $user, string $address): Collection
    {
        return collect($this->messages($user, ['inMailbox' => $user->screening_mailbox_id], ['id', 'from']))
            ->filter(fn (array $email) => $this->sender($email) === $address);
    }

    private function messages(User $user, array $filter, array $properties): iterable
    {
        $position = 0;
        do {
            $page = $this->mail->request('Email/query', [
                'filter' => $filter, 'position' => $position, 'limit' => 50,
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            ], $user);
            $ids = $page['ids'];
            if ($ids) {
                $items = collect($this->mail->request('Email/get', ['ids' => $ids, 'properties' => $properties], $user)['list'])->keyBy('id');
                foreach ($ids as $id) {
                    if ($items->has($id)) {
                        yield $items[$id];
                    }
                }
            }
            $position += count($ids);
        } while ($ids !== [] && count($ids) === ($page['limit'] ?? 50));
    }

    private function update(User $user, array $updates): void
    {
        foreach (array_chunk($updates, 50, true) as $batch) {
            $result = $this->mail->request('Email/set', ['update' => (object) $batch], $user);
            foreach ($batch as $id => $patch) {
                if (! array_key_exists($id, $result['updated'] ?? []) && ($result['notUpdated'][$id]['type'] ?? null) !== 'notFound') {
                    throw new RuntimeException('Screener mail filing failed.');
                }
            }
        }
    }
}
