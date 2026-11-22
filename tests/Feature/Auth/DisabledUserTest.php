<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class DisabledUserTest extends TestCase
{
    private function disabledUser(): User
    {
        return User::factory()->create(['email' => 'user@example.com', 'disabled_at' => now()]);
    }

    public function test_disabled_user_cannot_log_in_and_gets_the_generic_message(): void
    {
        $this->disabledUser();

        $this->from('/login')
            ->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Credenciais inválidas.']);

        $this->assertGuest();
    }

    public function test_disabled_user_with_two_factor_does_not_reach_challenge(): void
    {
        $user = $this->disabledUser();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->from('/login')
            ->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Credenciais inválidas.'])
            ->assertSessionMissing('login.id');

        $this->assertGuest();
    }

    public function test_old_remember_cookie_stops_working_after_disable(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'remember_token' => 'token-before-disable']);
        $recaller = [Auth::guard('web')->getRecallerName() => $user->id.'|token-before-disable|'.$user->password];

        $this->withCookies($recaller)->get('/')->assertOk();
        $this->assertAuthenticatedAs($user);

        Auth::forgetGuards();
        $this->artisan('user:disable', ['email' => 'user@example.com', '--force' => true]);

        $this->withCookies($recaller)->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_active_session_is_logged_out_by_middleware(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['disabled_at' => now()])->save();

        $this->actingAs($user->fresh())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_disabled_user_gets_no_reset_email_but_same_response(): void
    {
        Notification::fake();
        $user = $this->disabledUser();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'user@example.com'])
            ->assertSessionHas('status', 'Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.')
            ->assertSessionHasNoErrors();

        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_disabled_user_with_a_leftover_session_cannot_use_two_factor_endpoints(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/user/two-factor-authentication')
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull($user->fresh()->two_factor_secret);
    }
}
