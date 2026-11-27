<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private function user(): User
    {
        return User::factory()->create(['email' => 'user@example.com']);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('canResetPassword', true)
                ->has('status')
            );
    }

    public function test_user_can_login_and_session_is_regenerated(): void
    {
        $user = $this->user();
        $this->get('/login');
        $sessionBefore = session()->getId();

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionBefore, session()->getId());
    }

    public function test_login_is_case_and_whitespace_insensitive_for_email(): void
    {
        $user = $this->user();

        $this->post('/login', ['email' => ' User@Example.com ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_shows_generic_message(): void
    {
        $this->user();

        $this->from('/login')
            ->post('/login', ['email' => 'user@example.com', 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);

        $this->assertGuest();
    }

    public function test_unknown_email_shows_generic_message(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);

        $this->assertGuest();
    }

    public function test_unknown_and_disabled_users_still_cost_a_password_hash(): void
    {
        // Without a hash check the answer for an unknown e-mail comes back faster,
        // which tells an attacker which e-mails have an account.
        User::factory()->create(['email' => 'off@example.com', 'disabled_at' => now()]);
        Hash::spy();

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'password']);
        $this->post('/login', ['email' => 'off@example.com', 'password' => 'password']);

        Hash::shouldHaveReceived('check')->twice();
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $this->user();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    public function test_remember_me_sets_recaller_cookie(): void
    {
        $this->user();

        $response = $this->post('/login', ['email' => 'user@example.com', 'password' => 'password', 'remember' => true]);

        $recaller = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

        $this->assertNotNull($recaller);
    }

    public function test_logout_invalidates_session(): void
    {
        $this->actingAs($this->user())
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_passkey_routes_do_not_exist(): void
    {
        $this->get('/passkeys/login/options')->assertNotFound();
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs($this->user())
            ->get('/login')
            ->assertRedirect('/');
    }
}
