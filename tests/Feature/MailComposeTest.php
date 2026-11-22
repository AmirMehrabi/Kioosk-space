<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        $draftId = $this->actingAs($owner)->postJson('/mail/draft/open')->assertOk()->json('id');
        $attachment = $this->postJson("/mail/draft/$draftId/attachments", [
            'file' => UploadedFile::fake()->createWithContent('note.txt', 'attachment'),
        ])->assertCreated()->json('id');

        $draft = [
            'to' => ['first@example.test', 'second@example.test'],
            'cc' => ['copy@example.test'],
            'bcc' => ['hidden@example.test'],
            'subject' => 'A composed message',
            'bodyHtml' => '<p><strong>Readable</strong></p>',
            'revision' => 0,
            'attachments' => [$attachment],
        ];

        $otherDraft = $this->actingAs($other)->postJson('/mail/draft/open')->assertOk()->json('id');
        $this->putJson("/mail/draft/$draftId", $draft)->assertNotFound();
        $this->putJson("/mail/draft/$otherDraft", $draft)->assertUnprocessable();
        $revision = $this->actingAs($owner)->putJson("/mail/draft/$draftId", $draft)->assertOk()->json('revision');
        $this->postJson('/mail/draft/open')->assertJsonPath('draft.bcc', $draft['bcc']);

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

        $submission = ['draft' => $draftId, 'revision' => $revision];
        $this->postJson('/mail/send', $submission)->assertOk();
        $this->assertDatabaseMissing('mail_drafts', ['id' => $draftId]);
        $this->postJson('/mail/send', $submission)->assertOk();
        Http::assertSentCount(6);
    }

    public function test_replies_restore_per_conversation_and_send_one_safe_quote(): void
    {
        config(['stalwart.url' => 'https://stalwart.example.test']);
        $user = User::factory()->create(['mailbox_address' => 'owner@kioosk.space']);
        $user->forceFill(['mail_account_id' => 'account', 'mail_secret' => 'fake'])->save();
        $created = [];
        Http::fake(function (Request $request) use (&$created) {
            [$method, $arguments] = $request->data()['methodCalls'][0];
            if ($method === 'Email/set') {
                $created[] = $arguments['create']['message'];
            }
            $email = [
                'id' => $arguments['ids'][0] ?? '',
                'subject' => 'Original subject',
                'from' => [['email' => 'sender@example.test', 'name' => 'Original Sender']],
                'replyTo' => [['email' => 'reply@example.test']],
                'to' => [['email' => 'owner@kioosk.space'], ['email' => 'another@example.test']],
                'sentAt' => '2026-11-20T12:00:00Z',
                'htmlBody' => [['partId' => 'html']],
                'bodyValues' => ['html' => ['value' => '<p>Original contents</p><script>discard()</script>']],
                'messageId' => ['original@example.test'],
                'references' => ['earlier@example.test'],
            ];
            $email['threadId'] = $email['id'] === 'b' ? 'thread-b' : 'thread-a';

            return Http::response(['methodResponses' => [[$method, match ($method) {
                'Email/get' => ['list' => [$email]],
                'Mailbox/get' => ['list' => [['role' => 'drafts', 'id' => 'drafts'], ['role' => 'sent', 'id' => 'sent']]],
                'Identity/get' => ['list' => [['email' => 'owner@kioosk.space', 'id' => 'identity']]],
                'Email/set' => ['created' => ['message' => ['id' => 'message']]],
                'EmailSubmission/set' => ['created' => ['send' => ['id' => 'submission']]],
            }, 'call']]]);
        });

        $first = $this->actingAs($user)->postJson('/mail/draft/open', ['reply' => 'a'])
            ->assertOk()
            ->assertJsonPath('draft.to', ['reply@example.test'])
            ->assertJsonPath('draft.quote.html', '<p>Original contents</p>')->json('id');
        $data = [
            'to' => ['reply@example.test'], 'cc' => [], 'bcc' => [], 'subject' => 'Re: Original subject',
            'bodyHtml' => '<p><strong>Reply A</strong><script>discard()</script></p>', 'attachments' => [], 'revision' => 0,
        ];
        $this->putJson("/mail/draft/$first", $data)->assertOk()->assertJsonPath('revision', 1);
        $this->putJson("/mail/draft/$first", $data)->assertConflict();

        $second = $this->postJson('/mail/draft/open', ['reply' => 'b', 'all' => true])
            ->assertOk()->assertJsonPath('draft.bodyHtml', '')
            ->assertJsonPath('draft.to', ['reply@example.test', 'another@example.test'])->json('id');
        $this->assertNotSame($first, $second);
        $this->postJson('/mail/draft/open', ['reply' => 'same-thread'])
            ->assertJsonPath('id', $first)->assertJsonPath('draft.reply', 'a')
            ->assertJsonPath('draft.bodyHtml', '<p><strong>Reply A</strong></p>');
        $this->postJson('/mail/draft/open')->assertJsonPath('draft.bodyHtml', '')->assertJsonPath('draft.to', []);

        $this->postJson('/mail/send', ['draft' => $first, 'revision' => 1])->assertOk();
        $this->assertSame(['original@example.test'], $created[0]['inReplyTo']);
        $this->assertSame(['earlier@example.test', 'original@example.test'], $created[0]['references']);
        $this->assertSame(1, substr_count($created[0]['bodyValues']['html']['value'], 'Original contents'));
        $this->assertStringContainsString('Original Sender', $created[0]['bodyValues']['html']['value']);
        $this->assertStringContainsString('> Original contents', $created[0]['bodyValues']['text']['value']);
        $this->assertStringNotContainsString('discard()', $created[0]['bodyValues']['html']['value']);
        $this->putJson("/mail/draft/$first", $data)->assertNotFound();

        $this->postJson('/mail/draft/open', ['reply' => 'b'])->assertJsonPath('id', $second);
        $this->deleteJson("/mail/draft/$second", ['revision' => 0])->assertNoContent();
        $this->putJson("/mail/draft/$second", $data)->assertNotFound();
        $this->assertNotSame($second, $this->postJson('/mail/draft/open', ['reply' => 'b'])->json('id'));
    }

    public function test_legacy_markdown_is_restored_in_its_own_context(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['mail_draft' => ['to' => 'old@example.test', 'subject' => 'Old draft', 'body' => '**Saved**']])->save();

        $this->actingAs($user)->postJson('/mail/draft/open')
            ->assertOk()
            ->assertJsonPath('draft.to', ['old@example.test'])
            ->assertJsonPath('draft.bodyHtml', '<p><strong>Saved</strong></p>')
            ->assertJsonPath('hasLegacyDraft', false);
        $this->assertNull($user->fresh()->mail_draft);
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
