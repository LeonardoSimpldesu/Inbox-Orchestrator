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
    public function test_dashboard_renders_successfully_with_inertia(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('accounts')
            ->has('stats')
            ->has('recentRules')
        );
    }

    public function test_dashboard_displays_active_email_accounts_and_metrics(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'user@gmail.com',
            'access_token' => 'secret_access_token',
            'refresh_token' => 'secret_refresh_token',
            'is_active' => true,
        ]);

        EmailSender::create([
            'email_account_id' => $account->id,
            'email' => 'newsletter@techcrunch.com',
            'domain' => 'techcrunch.com',
            'total_messages_received' => 12,
            'total_messages_unread' => 5,
        ]);

        RetentionRule::create([
            'email_account_id' => $account->id,
            'name' => 'Expire promotions in 15 days',
            'filter_type' => 'category',
            'filter_value' => 'CATEGORY_PROMOTIONS',
            'retention_days' => 15,
            'action' => 'archive',
            'is_active' => true,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
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

        EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'user@gmail.com',
            'access_token' => 'leaked_access_token_abc',
            'refresh_token' => 'leaked_refresh_token_xyz',
            'history_id' => 'internal_history_cursor',
            'is_active' => true,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('leaked_access_token_abc', escape: false);
        $response->assertDontSee('leaked_refresh_token_xyz', escape: false);
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

        EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'disabled@gmail.com',
            'access_token' => 'token1',
            'refresh_token' => 'token2',
            'is_active' => false,
        ]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('accounts', 0)
        );
    }
}
