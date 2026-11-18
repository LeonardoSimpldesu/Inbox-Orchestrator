<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    private const PASSWORD = 'uma-senha-bem-longa-2026';

    public function test_it_creates_a_user_with_normalized_email(): void
    {
        $this->artisan('user:create', ['name' => 'Fulana', 'email' => ' New@Example.com ', '--password' => self::PASSWORD])
            ->expectsOutputToContain('Usuário new@example.com criado.')
            ->assertExitCode(0);

        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Fulana', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_it_asks_for_the_password_interactively(): void
    {
        $this->artisan('user:create', ['name' => 'Fulana', 'email' => 'new@example.com'])
            ->expectsQuestion('Senha', self::PASSWORD)
            ->expectsQuestion('Confirme a senha', self::PASSWORD)
            ->assertExitCode(0);

        $this->assertTrue(Hash::check(self::PASSWORD, User::where('email', 'new@example.com')->value('password')));
    }

    public function test_mismatched_confirmation_creates_nothing(): void
    {
        $this->artisan('user:create', ['name' => 'Fulana', 'email' => 'new@example.com'])
            ->expectsQuestion('Senha', self::PASSWORD)
            ->expectsQuestion('Confirme a senha', 'outra-senha-diferente')
            ->expectsOutputToContain('As senhas não conferem.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_non_interactive_run_generates_a_password_shown_once(): void
    {
        $exitCode = Artisan::call('user:create', ['name' => 'Fulana', 'email' => 'new@example.com', '--no-interaction' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/Senha gerada: (\S{24})\s*$/m', Artisan::output(), $match));

        $this->post('/login', ['email' => 'new@example.com', 'password' => $match[1]]);
        $this->assertAuthenticated();
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'new@example.com']);

        $this->artisan('user:create', ['name' => 'Outra', 'email' => 'NEW@example.com', '--password' => self::PASSWORD])
            ->expectsOutputToContain('Este e-mail já está em uso.')
            ->assertExitCode(1);

        $this->assertSame(1, User::count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->artisan('user:create', ['name' => 'Fulana', 'email' => 'not-an-email', '--password' => self::PASSWORD])
            ->expectsOutputToContain('Informe um e-mail válido.')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_short_password_is_rejected(): void
    {
        $this->artisan('user:create', ['name' => 'Fulana', 'email' => 'new@example.com', '--password' => 'curta-123'])
            ->expectsOutputToContain('O campo senha deve ter pelo menos 12 caracteres.')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_password_is_not_logged(): void
    {
        Log::spy();

        $this->artisan('user:create', ['name' => 'Fulana', 'email' => 'new@example.com', '--password' => self::PASSWORD])
            ->assertExitCode(0);

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$args) => str_contains(json_encode($args), self::PASSWORD));
        }
        $this->assertTrue(true);
    }
}
