<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScreenerTest extends TestCase
{
    use RefreshDatabase;

    private array $mail = [];

    private array $filters = [];

    private bool $failNextMove = false;

    private bool $hasArchive = true;

    public function test_queue_groups_senders_and_keeps_held_mail_out_of_regular_mail_paths(): void
    {
        $user = $this->mailbox();
        $this->receive('first', 'Sender@Example.com');
        $this->receive('second', 'sender@example.com');
        $this->fakeMail();

        $this->actingAs($user)->getJson('/mail/screener')
            ->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('senders.0.address', 'sender@example.com')
            ->assertJsonCount(2, 'senders.0.messages');
        $this->getJson('/mail/screener/preview/first')
            ->assertOk()->assertJsonPath('text', 'A preview')
            ->assertJsonPath('to.0', 'pilot@kioosk.space');
        $this->assertArrayNotHasKey('$seen', $this->mail['first']['keywords']);

        $this->getJson('/mail/data?folder=imbox&q=Sender')->assertOk()->assertJsonPath('messages', []);
        $this->assertSame(['operator' => 'NOT', 'conditions' => [['inMailbox' => 'hold']]], $this->filters[array_key_last($this->filters)]['conditions'][1]);
        $this->getJson('/mail/message/first')->assertNotFound();
        $this->patchJson('/mail/message/first', ['flag' => 'read', 'value' => true])->assertNotFound();
        $this->postJson('/mail/message/first/organize', ['action' => 'move', 'destination' => 'imbox'])->assertNotFound();
        $this->postJson('/mail/draft/open', ['reply' => 'first'])->assertNotFound();
        $this->postJson('/mail/screener/decision', ['address' => 'someone@example.com', 'destination' => 'imbox'])->assertNotFound();
    }

    public function test_approval_routes_waiting_and_future_mail_and_undo_asks_again(): void
    {
        $this->actingAs($this->mailbox());
        $this->receive('first', 'sender@example.com');
        $this->fakeMail();

        $approved = $this->postJson('/mail/screener/decision', ['address' => 'SENDER@example.com', 'destination' => 'feed'])
            ->assertOk()->assertJsonPath('syncing', false)->json();
        $this->assertSame(['archive' => true], $this->mail['first']['mailboxIds']);
        $this->assertTrue($this->mail['first']['keywords']['kioosk-feed']);
        $this->assertArrayNotHasKey('$seen', $this->mail['first']['keywords']);
        $this->getJson('/mail/screener?history=1')->assertOk()->assertJsonPath('history.0.destination', 'feed');

        $this->receive('second', 'sender@example.com');
        $this->artisan('mail:screener-sync')->assertSuccessful();
        $this->assertSame(['archive' => true], $this->mail['second']['mailboxIds']);

        $this->postJson('/mail/screener/decision', ['address' => 'sender@example.com', 'destination' => null, 'revision' => $approved['revision']])
            ->assertOk()->assertJsonPath('destination', null);
        $this->assertSame(['hold' => true], $this->mail['first']['mailboxIds']);
        $this->assertSame(['hold' => true], $this->mail['second']['mailboxIds']);
        $this->assertArrayNotHasKey('kioosk-feed', $this->mail['first']['keywords']);
        $this->getJson('/mail/screener')->assertOk()->assertJsonCount(2, 'senders.0.messages');
        $this->postJson('/mail/screener/decision', ['address' => 'sender@example.com', 'destination' => 'out', 'revision' => $approved['revision']])
            ->assertConflict();
    }

    public function test_history_restores_screened_out_mail_after_a_temporary_filing_failure(): void
    {
        $this->actingAs($this->mailbox());
        $this->receive('first', 'sender@example.com');
        $this->fakeMail();

        $blocked = $this->postJson('/mail/screener/decision', ['address' => 'sender@example.com', 'destination' => 'out'])
            ->assertOk()->json();
        $this->receive('second', 'sender@example.com');
        $this->artisan('mail:screener-sync')->assertSuccessful();
        $this->assertSame(['hold' => true], $this->mail['second']['mailboxIds']);
        $this->getJson('/mail/screener?count=1')->assertOk()->assertJsonPath('count', 0);

        $this->failNextMove = true;
        $this->postJson('/mail/screener/decision', ['address' => 'sender@example.com', 'destination' => 'receipts', 'revision' => $blocked['revision']])
            ->assertOk()->assertJsonPath('syncing', true);
        $this->assertSame(['hold' => true], $this->mail['first']['mailboxIds']);

        $this->artisan('mail:screener-sync')->assertSuccessful();
        $this->assertSame(['archive' => true], $this->mail['first']['mailboxIds']);
        $this->assertTrue($this->mail['second']['keywords']['kioosk-receipts']);
        $this->getJson('/mail/screener?history=1')->assertOk()->assertJsonPath('history.0.destination', 'receipts');
    }

    public function test_activation_uses_stalwart_delivery_filter_and_seeds_existing_correspondents(): void
    {
        $user = $this->mailbox(false);
        $this->receive('old', 'known@example.com', 'inbox');
        $this->hasArchive = false;
        $this->fakeMail();

        $this->artisan('mail:screener-enable', ['mailbox' => $user->mailbox_address])->assertSuccessful();
        $this->assertTrue($this->hasArchive);
        $this->assertTrue($user->refresh()->screening_enabled);
        $this->assertSame('hold', $user->screening_mailbox_id);
        $this->assertDatabaseHas('sender_decisions', ['user_id' => $user->id, 'address' => 'known@example.com', 'destination' => 'imbox']);
        $this->assertSame(['inbox' => true], $this->mail['old']['mailboxIds']);
    }

    private function mailbox(bool $enabled = true): User
    {
        config(['stalwart.url' => 'https://stalwart.example.test']);

        $user = User::factory()->create(['mailbox_address' => 'pilot@kioosk.space'])->forceFill([
            'mail_account_id' => 'pilot', 'mail_secret' => 'test-secret',
            'screening_enabled' => $enabled, 'screening_mailbox_id' => $enabled ? 'hold' : null,
        ]);
        $user->save();

        return $user;
    }

    private function receive(string $id, string $from, string $folder = 'hold'): void
    {
        $this->mail[$id] = [
            'id' => $id,
            'from' => [['name' => 'Sender', 'email' => $from]],
            'to' => [['email' => 'pilot@kioosk.space']],
            'subject' => 'Hello',
            'preview' => 'A preview',
            'receivedAt' => '2026-11-23T08:00:00Z',
            'mailboxIds' => [$folder => true],
            'keywords' => [],
        ];
    }

    private function fakeMail(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/jmap/session')) {
                return Http::response(['uploadUrl' => 'https://stalwart.example.test/jmap/upload/{accountId}/']);
            }
            if (str_contains($request->url(), '/jmap/upload/')) {
                $this->assertStringContainsString('spamtest', $request->body());
                $this->assertStringContainsString('fileinto "Kioosk Screener"', $request->body());

                return Http::response(['blobId' => 'script-blob']);
            }

            [$method, $args] = $request->data()['methodCalls'][0];
            $this->assertSame('pilot', $args['accountId']);
            $result = match ($method) {
                'Mailbox/get' => ['list' => [
                    ['id' => 'inbox', 'role' => 'inbox', 'name' => 'Inbox', 'parentId' => null],
                    ...($this->hasArchive ? [['id' => 'archive', 'role' => 'archive', 'name' => 'Archive', 'parentId' => null]] : []),
                ]],
                'Mailbox/set' => $this->createMailbox($args['create']),
                'Email/query' => $this->queryMessages($args),
                'Email/get' => ['list' => array_values(array_intersect_key($this->mail, array_flip($args['ids'])))],
                'Email/set' => $this->updateMessages((array) $args['update']),
                'SieveScript/get' => ['state' => 'scripts-1', 'list' => []],
                'SieveScript/set' => $this->activate($request, $args),
            };

            return Http::response(['methodResponses' => [[$method, $result, 'call']]]);
        });
    }

    private function createMailbox(array $create): array
    {
        if (isset($create['archive'])) {
            $this->assertSame('archive', $create['archive']['role']);
            $this->hasArchive = true;

            return ['created' => ['archive' => ['id' => 'archive']]];
        }

        return ['created' => ['screener' => ['id' => 'hold']]];
    }

    private function queryMessages(array $args): array
    {
        $filter = $args['filter'];
        $this->filters[] = $filter;
        $matching = array_filter($this->mail, fn (array $email) => $this->matchesFilter($email, $filter));

        return ['ids' => array_slice(array_keys($matching), $args['position'], $args['limit'])];
    }

    private function matchesFilter(array $email, array $filter): bool
    {
        return match ($filter['operator'] ?? null) {
            'AND' => collect($filter['conditions'])->every(fn (array $condition) => $this->matchesFilter($email, $condition)),
            'NOT' => ! collect($filter['conditions'])->contains(fn (array $condition) => $this->matchesFilter($email, $condition)),
            default => (! isset($filter['inMailbox']) || isset($email['mailboxIds'][$filter['inMailbox']]))
                && (! isset($filter['notKeyword']) || ! isset($email['keywords'][$filter['notKeyword']]))
                && (! isset($filter['hasKeyword']) || isset($email['keywords'][$filter['hasKeyword']])),
        };
    }

    private function updateMessages(array $updates): array
    {
        $result = ['updated' => [], 'notUpdated' => []];
        foreach ($updates as $id => $patch) {
            if ($this->failNextMove) {
                $this->failNextMove = false;
                $result['notUpdated'][$id] = ['type' => 'serverFail'];

                continue;
            }
            foreach ($patch as $key => $value) {
                if ($key === 'mailboxIds') {
                    $this->mail[$id][$key] = $value;
                } elseif ($value === null) {
                    unset($this->mail[$id]['keywords'][substr($key, strlen('keywords/'))]);
                } else {
                    $this->mail[$id]['keywords'][substr($key, strlen('keywords/'))] = $value;
                }
            }
            $result['updated'][$id] = null;
        }

        return $result;
    }

    private function activate(Request $request, array $args): array
    {
        $this->assertContains('urn:ietf:params:jmap:sieve', $request->data()['using']);
        $this->assertSame('#screener', $args['onSuccessActivateScript']);
        $this->assertSame('scripts-1', $args['ifInState']);

        return ['created' => ['screener' => ['id' => 'new-script', 'isActive' => true]]];
    }
}
