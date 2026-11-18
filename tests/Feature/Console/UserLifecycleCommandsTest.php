<?php

namespace Tests\Feature\Console;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserLifecycleCommandsTest extends TestCase
{
    private function sessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);
    }

    public function test_disable_marks_user_kills_sessions_and_rotates_remember_token(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'remember_token' => 'old-token']);
        $other = User::factory()->create();
        $this->sessionFor($user, 'session-user');
        $this->sessionFor($other, 'session-other');

        $this->artisan('user:disable', ['email' => ' USER@example.com ', '--force' => true])
            ->expectsOutputToContain('Usuário user@example.com desativado.')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertTrue($user->isDisabled());
        $this->assertNotSame('old-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-user']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-other']);
    }

    public function test_disable_asks_for_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->artisan('user:disable', ['email' => 'user@example.com'])
            ->expectsConfirmation('Desativar user@example.com? A pessoa será desconectada imediatamente.', 'no')
            ->assertExitCode(1);

        $this->assertFalse($user->fresh()->isDisabled());
    }

    public function test_unknown_email_fails_for_every_command(): void
    {
        foreach (['user:disable', 'user:enable', 'user:delete'] as $command) {
            $this->artisan($command, ['email' => 'nobody@example.com'])
                ->expectsOutputToContain('Usuário não encontrado.')
                ->assertExitCode(1);
        }
    }

    public function test_enable_lets_the_user_log_in_again(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'disabled_at' => now()]);

        $this->artisan('user:enable', ['email' => 'user@example.com'])
            ->expectsOutputToContain('Usuário user@example.com reativado.')
            ->assertExitCode(0);

        $this->assertFalse($user->fresh()->isDisabled());

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_delete_with_wrong_confirmation_aborts(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->artisan('user:delete', ['email' => 'user@example.com'])
            ->expectsQuestion('Para confirmar, digite o e-mail do usuário', 'outro@example.com')
            ->expectsOutputToContain('Confirmação incorreta. Nada foi apagado.')
            ->assertExitCode(1);

        $this->assertModelExists($user);
    }

    public function test_delete_removes_user_and_all_their_data(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);
        $other = User::factory()->create();
        $account = EmailAccount::create(['user_id' => $user->id, 'provider' => 'gmail', 'account_email' => 'a@gmail.com', 'access_token' => 'a', 'refresh_token' => 'r']);
        $otherAccount = EmailAccount::create(['user_id' => $other->id, 'provider' => 'gmail', 'account_email' => 'b@gmail.com', 'access_token' => 'a', 'refresh_token' => 'r']);
        $sender = EmailSender::create(['email_account_id' => $account->id, 'email' => 's@x.com', 'domain' => 'x.com']);
        EmailMessageMeta::create([
            'email_account_id' => $account->id,
            'provider_message_id' => 'm1',
            'provider_thread_id' => 't1',
            'email_sender_id' => $sender->id,
            'sender_email' => 's@x.com',
            'sender_domain' => 'x.com',
            'labels' => ['INBOX'],
            'received_at' => now(),
        ]);
        RetentionRule::create(['email_account_id' => $account->id, 'name' => 'R', 'filter_type' => 'domain', 'filter_value' => 'x.com', 'retention_days' => 7, 'action' => 'trash']);

        $this->artisan('user:delete', ['email' => 'user@example.com'])
            ->expectsQuestion('Para confirmar, digite o e-mail do usuário', 'User@Example.com')
            ->expectsOutputToContain('Usuário user@example.com removido.')
            ->assertExitCode(0);

        $this->assertModelMissing($user);
        $this->assertSame(0, EmailAccount::where('user_id', $user->id)->count());
        $this->assertSame(0, EmailSender::count());
        $this->assertSame(0, EmailMessageMeta::count());
        $this->assertSame(0, RetentionRule::count());
        $this->assertModelExists($otherAccount);
    }

    public function test_delete_with_force_skips_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->artisan('user:delete', ['email' => 'user@example.com', '--force' => true])->assertExitCode(0);

        $this->assertModelMissing($user);
    }
}
