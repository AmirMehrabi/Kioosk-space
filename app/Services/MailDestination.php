<?php

namespace App\Services;

use Illuminate\Support\Collection;
use RuntimeException;

class MailDestination
{
    public function patch(Collection $boxes, string $destination): array
    {
        $role = match ($destination) {
            'imbox' => 'inbox',
            'trash' => 'trash',
            default => 'archive',
        };
        if (! isset($boxes[$role])) {
            throw new RuntimeException('Destination mailbox is missing.');
        }

        return [
            'mailboxIds' => [$boxes[$role]['id'] => true],
            'keywords/kioosk-feed' => $destination === 'feed' ?: null,
            'keywords/kioosk-receipts' => $destination === 'receipts' ?: null,
        ];
    }
}
