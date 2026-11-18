<?php

namespace Tests\Feature\Accounts;

use App\Exceptions\MissingRequiredScopes;
use App\Exceptions\OAuthExchangeFailed;
use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeEmailProvider;

class ConnectAccountTest extends AccountsTestCase
{
    private const STATE = 'expected-state-value';

    private function hitCallback(User $user, array $query, ?string $sessionState = self::STATE)
    {
        $session = $sessionState === null ? [] : ['google_oauth_state' => $sessionState];

        return $this->actingAs($user)->withSession($session)->get('/accounts/google/callback?'.http_build_query($query));
    }

    private function connect(User $user, string $email): TestResponse
    {
        $this->provider->grant = FakeEmailProvider::grantFor($email);

        return $this->hitCallback($user, ['state' => self::STATE, 'code' => 'auth-code']);
    }

    public function test_redirect_stores_state_and_goes_to_google(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post('/accounts/google/redirect', [], ['X-Inertia' => 'true']);

        $state = session('google_oauth_state');
        $this->assertIsString($state);
        $this->assertSame(40, strlen($state));
        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', 'https://accounts.google.test/auth?state='.$state);
        $this->assertNull($this->provider->lastAuthorization['loginHint']);
    }

    public function test_redirect_uses_login_hint_for_own_account(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->needsReauth()->create(['account_email' => 'mine@gmail.com']);

        $this->actingAs($user)->post('/accounts/google/redirect', ['account' => $account->id])
            ->assertRedirect('https://accounts.google.test/auth?state='.session('google_oauth_state'));

        $this->assertSame('mine@gmail.com', $this->provider->lastAuthorization['loginHint']);
    }

    public function test_redirect_ignores_other_users_account_hint(): void
    {
        $other = EmailAccount::factory()->create(['account_email' => 'other@gmail.com']);

        $this->actingAs(User::factory()->create())->post('/accounts/google/redirect', ['account' => $other->id]);

        $this->assertNull($this->provider->lastAuthorization['loginHint']);
    }

    public function test_missing_or_wrong_state_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->provider->grant = FakeEmailProvider::grantFor('a@gmail.com');

        foreach ([
            [['state' => 'wrong', 'code' => 'c'], self::STATE],
            [['code' => 'c'], self::STATE],
            [['state' => self::STATE, 'code' => 'c'], null],
            [['state' => ['array'], 'code' => 'c'], self::STATE],
        ] as [$query, $sessionState]) {
            $this->hitCallback($user, $query, $sessionState)
                ->assertRedirect('/accounts')
                ->assertSessionHas('error', 'Não foi possível validar a resposta do Google. Tente novamente.');
        }

        $this->assertSame([], $this->provider->exchangedCodes);
        $this->assertSame(0, EmailAccount::count());
    }

    public function test_state_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $this->connect($user, 'a@gmail.com')->assertSessionHas('success');
        $this->assertNull(session('google_oauth_state'));

        $this->actingAs($user)->get('/accounts/google/callback?state='.self::STATE.'&code=auth-code')
            ->assertSessionHas('error', 'Não foi possível validar a resposta do Google. Tente novamente.');

        $this->assertCount(1, $this->provider->exchangedCodes);
    }

    public function test_access_denied_shows_cancelled(): void
    {
        $this->hitCallback(User::factory()->create(), ['state' => self::STATE, 'error' => 'access_denied'])
            ->assertRedirect('/accounts')
            ->assertSessionHas('error', 'Conexão cancelada.');

        $this->assertSame([], $this->provider->exchangedCodes);
    }

    public function test_other_google_error_or_missing_code_shows_generic_error(): void
    {
        $user = User::factory()->create();

        $this->hitCallback($user, ['state' => self::STATE, 'error' => 'server_error'])
            ->assertSessionHas('error', 'Não foi possível conectar a conta Gmail. Tente novamente.');
        $this->hitCallback($user, ['state' => self::STATE])
            ->assertSessionHas('error', 'Não foi possível conectar a conta Gmail. Tente novamente.');

        $this->assertSame([], $this->provider->exchangedCodes);
    }

    public function test_exchange_failure_shows_generic_error_and_logs_no_secrets(): void
    {
        Log::spy();
        $this->provider->exchangeException = new OAuthExchangeFailed('invalid_grant');

        $this->hitCallback(User::factory()->create(), ['state' => self::STATE, 'code' => 'secret-auth-code'])
            ->assertRedirect('/accounts')
            ->assertSessionHas('error', 'Não foi possível conectar a conta Gmail. Tente novamente.');

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $this->assertSame(['error' => 'invalid_grant'], $context);
            $this->assertStringNotContainsString('secret-auth-code', $message.json_encode($context));

            return true;
        });
        $this->assertSame(0, EmailAccount::count());
    }

    public function test_missing_scopes_rejected_without_revoking(): void
    {
        Log::spy();
        $this->provider->exchangeException = new MissingRequiredScopes('missing_scopes');

        $this->hitCallback(User::factory()->create(), ['state' => self::STATE, 'code' => 'c'])
            ->assertSessionHas('error', 'Para funcionar, o Inbox Orchestrator precisa de todas as permissões pedidas. Conecte de novo e marque todas as opções.');

        $this->assertSame(0, EmailAccount::count());
        $this->assertSame([], $this->provider->revoked);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_missing_refresh_token_rejected(): void
    {
        $this->provider->grant = FakeEmailProvider::grantFor('a@gmail.com', ['refreshToken' => null]);

        $this->hitCallback(User::factory()->create(), ['state' => self::STATE, 'code' => 'c'])
            ->assertSessionHas('error', 'Não foi possível conectar a conta Gmail. Tente novamente.');

        $this->assertSame(0, EmailAccount::count());
        $this->assertSame([], $this->provider->revoked);
    }

    public function test_gmail_active_for_other_user_rejected_without_revoking(): void
    {
        $owner = EmailAccount::factory()->create(['account_email' => 'shared@gmail.com', 'refresh_token' => 'owner-refresh']);

        $this->connect(User::factory()->create(), 'shared@gmail.com')
            ->assertSessionHas('error', 'Esta conta Gmail já está conectada por outro usuário.');

        $this->assertSame(1, EmailAccount::count());
        $this->assertSame('owner-refresh', $owner->fresh()->refresh_token);
        $this->assertSame([], $this->provider->revoked);
    }

    public function test_connect_creates_account_and_dispatches_job(): void
    {
        Queue::fake();
        $this->freezeTime();
        $user = User::factory()->create();

        $this->connect($user, 'new@gmail.com')
            ->assertRedirect('/accounts')
            ->assertSessionHas('success', 'Conta new@gmail.com conectada.');

        $account = $user->emailAccounts()->sole();
        $this->assertSame('gmail', $account->provider);
        $this->assertSame('new@gmail.com', $account->account_email);
        $this->assertSame('access-new@gmail.com', $account->access_token);
        $this->assertSame('refresh-new@gmail.com', $account->refresh_token);
        $this->assertSame(now()->addHour()->timestamp, $account->token_expires_at->timestamp);
        $this->assertSame(now()->timestamp, $account->authorized_at->timestamp);
        Queue::assertPushed(SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => $job->accountId === $account->id);
    }

    public function test_reconnect_restores_disconnected_account_and_clears_reauth(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->needsReauth()->create([
            'account_email' => 'back@gmail.com',
            'authorized_at' => now()->subDays(10),
        ]);
        EmailSender::create(['email_account_id' => $account->id, 'email' => 'n@x.com', 'domain' => 'x.com']);
        $account->delete();

        $this->connect($user, 'back@gmail.com')
            ->assertSessionHas('success', 'Conta back@gmail.com reconectada.');

        $account = $account->fresh();
        $this->assertFalse($account->trashed());
        $this->assertNull($account->reauth_required_at);
        $this->assertSame('refresh-back@gmail.com', $account->refresh_token);
        $this->assertTrue($account->authorized_at->isToday());
        $this->assertSame(1, $account->senders()->count());
        $this->assertSame(1, EmailAccount::withTrashed()->count());
    }

    public function test_gmail_released_by_other_user_creates_fresh_account(): void
    {
        Queue::fake();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $old = EmailAccount::factory()->for($alice)->create(['account_email' => 'shared@gmail.com']);
        EmailSender::create(['email_account_id' => $old->id, 'email' => 'a@x.com', 'domain' => 'x.com']);
        RetentionRule::create(['email_account_id' => $old->id, 'name' => 'Regra da Alice', 'filter_type' => 'domain', 'filter_value' => 'x.com', 'retention_days' => 7, 'action' => 'trash']);
        $old->delete();

        $this->connect($bob, 'shared@gmail.com')->assertSessionHas('success', 'Conta shared@gmail.com conectada.');

        $new = $bob->emailAccounts()->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(0, $new->senders()->count());
        $this->assertTrue($old->fresh()->trashed());

        $this->actingAs($bob)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('stats.totalSenders', 0)
            ->has('recentRules', 0)
        );
    }
}
