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
    private function accountFor(User $user, string $email, bool $active = true): EmailAccount
    {
        return EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => $email,
            'access_token' => 'access_'.$email,
            'refresh_token' => 'refresh_'.$email,
            'history_id' => 'cursor_'.$email,
            'is_active' => $active,
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
        ]);
    }

    private function ruleFor(EmailAccount $account, string $name): RetentionRule
    {
        return RetentionRule::create([
            'email_account_id' => $account->id,
            'name' => $name,
            'filter_type' => 'category',
            'filter_value' => 'CATEGORY_PROMOTIONS',
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
                ->hasAll(['id', 'provider', 'account_email', 'last_synced_at', 'is_active'])
                ->missingAll(['access_token', 'refresh_token', 'history_id', 'user_id'])
            )
        );
    }

    public function test_inactive_email_accounts_are_not_displayed_in_active_list(): void
    {
        $user = User::factory()->create();
        $this->accountFor($user, 'disabled@gmail.com', active: false);

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('accounts', 0)
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
}
