<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class SecurityPageTest extends TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/settings/security')->assertRedirect('/login');
    }

    public function test_password_must_be_confirmed_first(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/security')
            ->assertRedirect('/user/confirm-password');
    }

    public function test_confirm_password_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/user/confirm-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ConfirmPassword'));
    }

    public function test_wrong_password_is_rejected_on_confirmation(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/user/confirm-password', ['password' => 'wrong-password'])
            ->assertSessionHasErrors(['password' => 'A senha informada está incorreta.']);
    }

    public function test_page_renders_after_confirming_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/user/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->get('/settings/security')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Security')
                ->where('twoFactorEnabled', false)
                ->where('twoFactorPending', false)
                ->has('status')
            );
    }

    public function test_page_reports_pending_two_factor_setup(): void
    {
        $user = User::factory()->create();
        app(EnableTwoFactorAuthentication::class)($user);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/security')
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactorEnabled', false)
                ->where('twoFactorPending', true)
            );
    }

    public function test_intended_url_is_preserved_after_login(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->get('/settings/security')->assertRedirect('/login');

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertRedirect('/settings/security');
    }

    public function test_password_login_counts_as_a_recent_password_confirmation(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])->assertRedirect('/');

        $this->get('/settings/security')->assertOk();
    }

    public function test_remember_cookie_login_still_asks_for_the_password(): void
    {
        $user = User::factory()->create(['remember_token' => 'remember-me-token']);
        $recaller = [Auth::guard('web')->getRecallerName() => $user->id.'|remember-me-token|'.$user->password];

        $this->withCookies($recaller)->get('/settings/security')->assertRedirect('/user/confirm-password');
        $this->assertAuthenticatedAs($user);
    }
}
