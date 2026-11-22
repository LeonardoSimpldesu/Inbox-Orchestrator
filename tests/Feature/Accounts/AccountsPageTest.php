<?php

namespace Tests\Feature\Accounts;

use App\Models\EmailAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

class AccountsPageTest extends AccountsTestCase
{
    public function test_guest_redirected_to_login(): void
    {
        $this->get('/accounts')->assertRedirect('/login');
        $this->post('/accounts/google/redirect')->assertRedirect('/login');
        $this->get('/accounts/google/callback?state=x&code=y')->assertRedirect('/login');
        $this->delete('/accounts/1')->assertRedirect('/login');
    }

    public function test_lists_only_own_non_disconnected_accounts(): void
    {
        config(['services.google.testing' => true]);
        $user = User::factory()->create();
        EmailAccount::factory()->for($user)->create(['account_email' => 'mine@gmail.com']);
        EmailAccount::factory()->for($user)->disconnected()->create(['account_email' => 'old@gmail.com']);
        EmailAccount::factory()->create(['account_email' => 'someone@gmail.com']);

        $this->actingAs($user)->get('/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Index')
                ->where('testingMode', true)
                ->has('accounts', 1)
                ->where('accounts.0.account_email', 'mine@gmail.com')
                ->where('accounts.0.status', 'connected')
            );
    }

    public function test_page_never_contains_tokens(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['history_id' => 'history-secret']);

        $response = $this->actingAs($user)->get('/accounts');

        $response->assertDontSee($account->access_token, escape: false);
        $response->assertDontSee($account->refresh_token, escape: false);
        $response->assertDontSee('history-secret', escape: false);
    }

    public function test_lists_own_disconnected_accounts_with_purge_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00'));
        $user = User::factory()->create();
        EmailAccount::factory()->for($user)->create(['account_email' => 'active@gmail.com']);
        EmailAccount::factory()->for($user)->create(['account_email' => 'old@gmail.com', 'deleted_at' => '2026-11-01 10:00:00']);
        EmailAccount::factory()->create(['account_email' => 'other@gmail.com', 'deleted_at' => now()]);

        $this->actingAs($user)->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->has('disconnected', 1)
            ->where('disconnected.0.account_email', 'old@gmail.com')
            ->where('disconnected.0.purge_at', '2027-01-30')
            ->has('disconnected.0', fn (Assert $row) => $row->hasAll(['id', 'account_email', 'purge_at']))
        );
    }
}
