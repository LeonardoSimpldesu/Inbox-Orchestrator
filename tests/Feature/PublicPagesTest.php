<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    public function test_guest_can_open_about_page(): void
    {
        $this->get('/sobre')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Public/About'));
    }

    public function test_guest_can_open_privacy_page_with_configured_contact(): void
    {
        config([
            'privacy.controller_name' => 'Responsável Exemplo',
            'privacy.contact_email' => 'contato@example.com',
            'app.disconnected_account_retention_days' => 90,
        ]);

        $this->get('/privacidade')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Privacy')
                ->where('controllerName', 'Responsável Exemplo')
                ->where('contactEmail', 'contato@example.com')
                ->where('disconnectedRetentionDays', 90)
                ->where('updatedAt', '2026-11-25'));
    }

    public function test_root_still_requires_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_logged_in_user_can_open_public_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/sobre')->assertOk();
        $this->get('/privacidade')->assertOk();
    }
}
