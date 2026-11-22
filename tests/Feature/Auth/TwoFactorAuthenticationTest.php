<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    private function confirmedPassword(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }

    private function userWithConfirmedTwoFactor(): User
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    private function loginExpectingChallenge(): void
    {
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertRedirect('/two-factor-challenge');

        $this->assertGuest();
    }

    /**
     * @return list<string>
     */
    private function recoveryCodes(User $user): array
    {
        return json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
    }

    public function test_enabling_requires_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/user/two-factor-authentication')
            ->assertRedirect('/user/confirm-password');

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_user_can_enable_and_confirm_two_factor(): void
    {
        $user = User::factory()->create();

        $this->confirmedPassword($user)->post('/user/two-factor-authentication')->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->confirmedPassword($user)
            ->post('/user/confirmed-two-factor-authentication', ['code' => $this->currentCode($user)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_invalid_confirmation_code_is_rejected_in_portuguese(): void
    {
        $user = User::factory()->create();
        $this->confirmedPassword($user)->post('/user/two-factor-authentication');

        $this->confirmedPassword($user)
            ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', [
                'code' => 'O código de autenticação em dois fatores é inválido.',
            ]);

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_login_with_two_factor_goes_to_challenge(): void
    {
        $this->userWithConfirmedTwoFactor();

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])
            ->assertRedirect('/two-factor-challenge');

        $this->assertGuest();

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));
    }

    public function test_valid_code_completes_login(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $this->loginExpectingChallenge();

        $this->post('/two-factor-challenge', ['code' => $this->currentCode($user)])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_works_only_once(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $code = $this->recoveryCodes($user)[0];

        $this->loginExpectingChallenge();
        $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->loginExpectingChallenge();
        $this->post('/two-factor-challenge', ['recovery_code' => $code])
            ->assertSessionHasErrors(['recovery_code' => 'O código de recuperação é inválido.']);

        $this->assertGuest();
    }

    public function test_two_factor_challenge_without_pending_login_redirects_to_login(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
    }

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        $this->userWithConfirmedTwoFactor();
        $this->loginExpectingChallenge();

        foreach (range(1, 5) as $attempt) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_user_can_disable_two_factor(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->confirmedPassword($user)->delete('/user/two-factor-authentication')->assertRedirect();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->post('/logout');
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_secrets_are_hidden(): void
    {
        $user = $this->userWithConfirmedTwoFactor();

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());

        $response = $this->confirmedPassword($user)->get('/settings/security');

        $response->assertOk();
        $response->assertDontSee($user->two_factor_secret, escape: false);
        $response->assertDontSee($this->recoveryCodes($user)[0], escape: false);
    }

    public function test_two_factor_login_counts_as_a_recent_password_confirmation(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $this->loginExpectingChallenge();

        $this->post('/two-factor-challenge', ['code' => $this->currentCode($user)])->assertRedirect('/');

        $this->get('/settings/security')->assertOk();
    }
}
