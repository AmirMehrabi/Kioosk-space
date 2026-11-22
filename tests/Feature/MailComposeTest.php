<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailComposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_attachments_are_private_and_submission_has_all_recipient_fields(): void
    {
        Storage::fake('local');
        config(['stalwart.url' => 'https://stalwart.example.test']);
        $owner = User::factory()->create(['mailbox_address' => 'owner@kioosk.space']);
        $other = User::factory()->create(['mailbox_address' => 'other@kioosk.space']);
        foreach ([$owner, $other] as $account) {
            $account->forceFill(['mail_account_id' => (string) $account->id, 'mail_secret' => 'fake'])->save();
        }

        $attachment = $this->actingAs($owner)->postJson('/mail/draft/attachments', [
            'file' => UploadedFile::fake()->createWithContent('note.txt', 'attachment'),
        ])->assertCreated()->json('id');

        $draft = [
            'to' => ['first@example.test', 'second@example.test'],
            'cc' => ['copy@example.test'],
            'bcc' => ['hidden@example.test'],
            'subject' => 'A composed message',
            'body' => '**Readable**',
            'attachments' => [$attachment],
        ];

        $this->actingAs($other)->putJson('/mail/draft', $draft)->assertUnprocessable();
        $this->actingAs($owner)->putJson('/mail/draft', $draft)->assertOk();
        $this->assertSame($draft['bcc'], $owner->fresh()->mail_draft['bcc']);

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/jmap/session')) {
                return Http::response(['uploadUrl' => 'https://stalwart.example.test/jmap/upload/{accountId}']);
            }
            if (str_contains($request->url(), '/jmap/upload/')) {
                $this->assertSame('attachment', $request->body());

                return Http::response(['blobId' => 'blob-test']);
            }
            [$method, $arguments] = $request->data()['methodCalls'][0];
            if ($method === 'Email/set') {
                $message = $arguments['create']['message'];
                $this->assertSame(['first@example.test', 'second@example.test'], array_column($message['to'], 'email'));
                $this->assertSame('copy@example.test', $message['cc'][0]['email']);
                $this->assertSame('hidden@example.test', $message['bcc'][0]['email']);
                $this->assertSame('blob-test', $message['attachments'][0]['blobId']);
                $this->assertSame('Readable', $message['bodyValues']['text']['value']);
                $this->assertStringContainsString('<strong>Readable</strong>', $message['bodyValues']['html']['value']);
            }

            return Http::response(['methodResponses' => [[$method, match ($method) {
                'Mailbox/get' => ['list' => [['role' => 'drafts', 'id' => 'drafts'], ['role' => 'sent', 'id' => 'sent']]],
                'Identity/get' => ['list' => [['email' => 'owner@kioosk.space', 'id' => 'identity']]],
                'Email/set' => ['created' => ['message' => ['id' => 'message']]],
                'EmailSubmission/set' => ['created' => ['send' => ['id' => 'submission']]],
            }, 'call']]]);
        });

        $draft['submission'] = (string) Str::uuid();
        $this->postJson('/mail/send', $draft)->assertOk();
        $this->assertNull($owner->fresh()->mail_draft);
        $this->postJson('/mail/send', $draft)->assertOk();
        Http::assertSentCount(6);
    }

    public function test_thread_actions_update_only_messages_in_the_signed_in_mailbox(): void
    {
        config(['stalwart.url' => 'https://stalwart.example.test']);
        $user = User::factory()->create(['mailbox_address' => 'owner@kioosk.space']);
        $user->forceFill(['mail_account_id' => 'account', 'mail_secret' => 'fake'])->save();
        $patches = [];
        Http::fake(function (Request $request) use (&$patches) {
            [$method, $arguments] = $request->data()['methodCalls'][0];
            $this->assertSame('account', $arguments['accountId']);
            if ($method === 'Email/set') {
                $patches[] = $arguments['update']['message'];
            }

            return Http::response(['methodResponses' => [[$method, match ($method) {
                'Email/get' => ['list' => $arguments['ids'] === ['message'] ? [['id' => 'message']] : []],
                'Mailbox/get' => ['list' => [['role' => 'archive', 'id' => 'archive'], ['role' => 'trash', 'id' => 'trash']]],
                'Email/set' => ['updated' => ['message' => null]],
            }, 'call']]]);
        });

        $this->actingAs($user)->postJson('/mail/message/missing/organize', ['action' => 'move', 'destination' => 'trash'])->assertNotFound();
        $this->postJson('/mail/message/message/organize', ['action' => 'move', 'destination' => 'feed'])->assertOk();
        $this->postJson('/mail/message/message/organize', ['action' => 'label', 'label' => 'کار'])->assertOk();
        $this->postJson('/mail/message/message/organize', ['action' => 'unlabel', 'label' => 'کار'])->assertOk();
        $this->postJson('/mail/message/message/organize', ['action' => 'move', 'destination' => 'trash'])->assertOk();

        $keyword = 'keywords/kioosk-label-'.bin2hex('کار');
        $this->assertSame([
            ['mailboxIds' => ['archive' => true], 'keywords/kioosk-feed' => true, 'keywords/kioosk-receipts' => null],
            [$keyword => true],
            [$keyword => null],
            ['mailboxIds' => ['trash' => true], 'keywords/kioosk-feed' => null, 'keywords/kioosk-receipts' => null],
        ], $patches);
    }
}
