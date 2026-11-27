<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private function accountFor(User $user, string $email): EmailAccount
    {
        return EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => $email,
            'access_token' => 'access_'.$email,
            'refresh_token' => 'refresh_'.$email,
            'history_id' => 'cursor_'.$email,
            'authorized_at' => now(),
        ]);
    }

    private function senderFor(EmailAccount $account, string $email): EmailSender
    {
        return EmailSender::create([
            'email_account_id' => $account->id,
            'email' => $email,
            'domain' => str($email)->after('@')->toString(),
            'total_messages_received' => 1,
            'total_messages_unread' => 1,
            'unsubscribe_mailto' => 'mailto:unsubscribe@'.str($email)->after('@'),
        ]);
    }

    private function ruleFor(EmailAccount $account, string $name): RetentionRule
    {
        return RetentionRule::factory()->withConditions([['category', 'CATEGORY_PROMOTIONS']])->create([
            'email_account_id' => $account->id,
            'name' => $name,
            'retention_days' => 15,
            'action' => 'archive',
            'is_active' => true,
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/up')->assertOk();
    }

    public function test_dashboard_renders_successfully_with_inertia(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('accounts')
                ->has('stats')
                ->has('recentRules')
            );
    }

    public function test_dashboard_displays_active_email_accounts_and_metrics(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user, 'user@gmail.com');
        $this->senderFor($account, 'newsletter@techcrunch.com');
        $this->ruleFor($account, 'Expire promotions in 15 days');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('accounts', 1)
                ->where('accounts.0.account_email', 'user@gmail.com')
                ->where('stats.totalSenders', 1)
                ->has('recentRules', 1)
                ->where('recentRules.0.name', 'Expire promotions in 15 days')
                ->has('recentRules.0', fn (Assert $rule) => $rule
                    ->hasAll(['id', 'name', 'retention_days', 'action', 'is_active'])
                    ->missing('filter_type')
                    ->missingAll(['laravel_through_key', 'email_account_id'])
                )
            );
    }

    public function test_dashboard_never_exposes_oauth_tokens(): void
    {
        $user = User::factory()->create();
        $this->accountFor($user, 'user@gmail.com');

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertDontSee('access_user@gmail.com', escape: false);
        $response->assertDontSee('refresh_user@gmail.com', escape: false);
        $response->assertInertia(fn (Assert $page) => $page
            ->has('accounts.0', fn (Assert $account) => $account
                ->hasAll(['id', 'provider', 'account_email', 'last_synced_at', 'status', 'reconnect_by', 'expiring_soon', 'sync'])
                ->missingAll(['access_token', 'refresh_token', 'history_id', 'user_id', 'authorized_at', 'reauth_required_at', 'deleted_at'])
            )
        );
    }

    public function test_disconnected_accounts_and_their_data_are_hidden(): void
    {
        $user = User::factory()->create();
        $kept = $this->accountFor($user, 'kept@gmail.com');
        $gone = $this->accountFor($user, 'gone@gmail.com');
        $this->senderFor($gone, 'news@gone.com');
        $this->ruleFor($gone, 'Regra da conta desconectada');
        $gone->delete();

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.account_email', 'kept@gmail.com')
                ->where('stats.totalSenders', 0)
                ->has('recentRules', 0)
                ->has('auth.user.accounts', 1)
                ->where('auth.user.accounts.0.account_email', $kept->account_email)
            );
    }

    public function test_dashboard_only_shows_the_authenticated_users_data(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceAccount = $this->accountFor($alice, 'alice@gmail.com');
        $this->senderFor($aliceAccount, 'news@a.com');
        $this->senderFor($aliceAccount, 'promo@a.com');
        $this->ruleFor($aliceAccount, 'Regra da Alice');

        $bobAccount = $this->accountFor($bob, 'bob@gmail.com');
        $this->senderFor($bobAccount, 'news@b.com');
        $this->senderFor($bobAccount, 'promo@b.com');
        $this->senderFor($bobAccount, 'other@b.com');
        $this->ruleFor($bobAccount, 'Regra do Bob');

        $response = $this->actingAs($alice)->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.account_email', 'alice@gmail.com')
            ->where('stats.totalSenders', 2)
            ->has('recentRules', 1)
            ->where('recentRules.0.name', 'Regra da Alice')
            ->has('auth.user.accounts', 1)
            ->where('auth.user.accounts.0.account_email', 'alice@gmail.com')
        );
        $response->assertDontSee('bob@gmail.com');
        $response->assertDontSee('Regra do Bob');
    }

    public function test_shared_auth_user_props_never_expose_secrets(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('auth.user', fn (Assert $shared) => $shared
                ->where('id', $user->id)
                ->where('name', $user->name)
                ->where('email', $user->email)
                ->where('two_factor_enabled', false)
                ->has('accounts')
            )
        );
        $response->assertDontSee($user->password, escape: false);
    }

    public function test_dashboard_counts_pending_subscriptions_only(): void
    {
        $user = User::factory()->create();
        $account = $this->accountFor($user, 'me@gmail.com');
        $base = ['email_account_id' => $account->id, 'total_messages_received' => 2];
        EmailSender::create($base + ['email' => 'a@x.com', 'domain' => 'x.com', 'unsubscribe_mailto' => 'mailto:u@x.com']);
        EmailSender::create($base + ['email' => 'b@x.com', 'domain' => 'x.com', 'unsubscribe_mailto' => 'mailto:u@x.com', 'unsubscribe_status' => 'requested']);
        EmailSender::create($base + ['email' => 'friend@y.com', 'domain' => 'y.com']);
        EmailSender::create(['email_account_id' => $account->id, 'email' => 'old@x.com', 'domain' => 'x.com', 'total_messages_received' => 0, 'unsubscribe_mailto' => 'mailto:u@x.com']);

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page->where('stats.totalSenders', 1));
    }
}
