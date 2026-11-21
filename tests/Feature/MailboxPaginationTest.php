<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailboxPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_query_serializes_its_http_offset_as_a_jmap_integer(): void
    {
        config(['stalwart.url' => 'https://stalwart.example.test']);
        $user = User::factory()->create(['mailbox_address' => 'pilot@kioosk.space']);
        $user->forceFill(['mail_account_id' => 'pilot', 'mail_secret' => 'test-secret'])->save();

        Http::fake(function (Request $request) {
            [$method, $arguments] = $request->data()['methodCalls'][0];

            if ($method === 'Mailbox/get') {
                return Http::response(['methodResponses' => [[$method, ['list' => [['id' => 'inbox', 'role' => 'inbox']]], 'call']]]);
            }

            $this->assertSame('Email/query', $method);
            $this->assertSame(35, $arguments['position']);

            return Http::response(['methodResponses' => [[$method, ['ids' => []], 'call']]]);
        });

        $this->actingAs($user)->getJson('/mail/data?folder=imbox&q=&position=35')
            ->assertOk()->assertJsonPath('messages', []);
    }
}
