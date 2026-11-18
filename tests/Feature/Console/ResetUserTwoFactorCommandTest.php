<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class ResetUserTwoFactorCommandTest extends TestCase
{
    private function userWithTwoFactor(): User
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    public function test_force_clears_two_factor_and_next_login_skips_the_challenge(): void
    {
        $user = $this->userWithTwoFactor();

        $this->artisan('user:reset-two-factor', ['email' => 'USER@example.com', '--force' => true])
            ->expectsOutputToContain('Verificação em duas etapas de user@example.com desativada.')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $user = $this->userWithTwoFactor();

        $this->artisan('user:reset-two-factor', ['email' => 'user@example.com'])
            ->expectsConfirmation('Desativar a verificação em duas etapas de user@example.com?', 'no')
            ->assertExitCode(1);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_unknown_email_fails(): void
    {
        $this->artisan('user:reset-two-factor', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('Usuário não encontrado.')
            ->assertExitCode(1);
    }
}
