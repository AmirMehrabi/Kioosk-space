<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_creates_a_mailbox_and_requires_a_signed_recovery_email_confirmation(): void
    {
        Notification::fake();

        $this->get('/mail')->assertRedirect(route('login'));
        $this->get('/mail/compose')->assertRedirect(route('login'));
        $this->get('/register/security')->assertRedirect(route('register'));
        $this->post('/register', ['name' => 'آوا رضایی'])->assertRedirect('/register/address');
        $this->post('/register/address', ['handle' => 'Ava'])->assertRedirect('/register/security');
        $this->post('/register/security', [
            'email' => 'Recovery@example.com',
            'password' => 'A distinct test password!',
            'password_confirmation' => 'A distinct test password!',
        ])->assertRedirect(route('verification.notice'));

        $user = User::where('mailbox_address', 'ava@kioosk.space')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('recovery@example.com', $user->email);
        $this->assertTrue(Hash::check('A distinct test password!', $user->password));

        $this->get('/mail')->assertRedirect(route('verification.notice'));

        $notice = Notification::sent($user, VerifyEmail::class)->sole();
        $url = $notice->toMail($user)->actionUrl;
        $this->get($url.'&tampered=1')->assertForbidden();
        $this->get($url)->assertRedirect(route('imbox'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/mail')->assertOk()->assertSee('ava@kioosk.space')->assertSee('آوا رضایی');
        $this->get('/mail/compose')->assertOk();

        $this->post('/logout')->assertRedirect(route('landing'));
        $this->assertGuest();
    }

    public function test_addresses_are_reserved_and_duplicate_claims_are_rechecked_on_submission(): void
    {
        $this->post('/register', ['name' => 'آوا رضایی']);
        $this->from('/register/address')->post('/register/address', ['handle' => 'Support'])->assertSessionHasErrors('handle');
        $this->post('/register/address', ['handle' => 'ava'])->assertRedirect('/register/security');

        User::factory()->create(['mailbox_address' => 'ava@kioosk.space']);

        $this->post('/register/security', [
            'email' => 'recovery@example.com',
            'password' => 'A distinct test password!',
            'password_confirmation' => 'A distinct test password!',
        ])->assertRedirect('/register/address')->assertSessionHasErrors('handle');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_recovery_reset_replaces_the_password_and_the_link_cannot_be_reused(): void
    {
        Notification::fake();
        $user = User::factory()->create(['mailbox_address' => 'ava@kioosk.space']);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $notice = Notification::sent($user, ResetPassword::class)->sole();
        $this->get($notice->toMail($user)->actionUrl)->assertOk();

        $data = [
            'token' => $notice->token,
            'email' => $user->email,
            'password' => 'A fresh account password!',
            'password_confirmation' => 'A fresh account password!',
        ];

        $this->post('/reset-password', $data)->assertRedirect(route('login'));
        $this->from('/login')->post('/reset-password', $data)->assertSessionHasErrors('email');

        $this->from('/login')->post('/login', [
            'email' => 'ava@kioosk.space',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'AVA@KIOOSK.SPACE',
            'password' => $data['password'],
        ])->assertRedirect(route('imbox'));

        $this->assertAuthenticatedAs($user);
    }
}
