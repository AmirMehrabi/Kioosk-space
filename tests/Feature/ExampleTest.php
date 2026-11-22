<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_landing_page_offers_registration_and_login_to_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="/register"', false)
            ->assertSee('href="/login"', false)
            ->assertSee('href="#folders"', false)
            ->assertSee('href="#later"', false)
            ->assertSee('href="/mail#later"', false);
    }

    public function test_the_landing_page_links_signed_in_users_to_their_mail_folders(): void
    {
        $this->actingAs(User::factory()->make())
            ->get('/')
            ->assertOk()
            ->assertSee('href="/mail"', false)
            ->assertSee('href="/mail#receipts"', false)
            ->assertSee('href="/mail#feed"', false)
            ->assertSee('href="/mail#later"', false)
            ->assertDontSee('href="/register"', false)
            ->assertDontSee('href="/login"', false);
    }
}
