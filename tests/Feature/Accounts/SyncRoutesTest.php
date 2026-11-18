<?php

namespace Tests\Feature\Accounts;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Sync\SyncMode;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

class SyncRoutesTest extends AccountsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_import_history_dispatches_history_now_for_own_account(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->create(['history_id' => '1']);

        $this->actingAs($user)->post("/accounts/{$account->id}/import-history")
            ->assertRedirect('/accounts')
            ->assertSessionHas('success', 'Importação do histórico iniciada. Pode levar até 20 minutos.');

        Queue::assertPushed(SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => $job->accountId === $account->id && $job->mode === SyncMode::HistoryNow);
    }

    public function test_import_history_404_for_other_users_account(): void
    {
        $foreign = EmailAccount::factory()->create();
        $gone = EmailAccount::factory()->disconnected()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post("/accounts/{$foreign->id}/import-history")->assertNotFound();
        $this->actingAs($user)->post("/accounts/{$gone->id}/import-history")->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_import_history_refused_when_needs_reauth(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->for($user)->needsReauth()->create();

        $this->actingAs($user)->post("/accounts/{$account->id}/import-history")
            ->assertRedirect('/accounts')
            ->assertSessionHas('error', 'Reconecte a conta antes de importar o histórico.');

        Queue::assertNothingPushed();
    }

    public function test_sync_all_dispatches_changes_for_own_eligible_accounts(): void
    {
        $user = User::factory()->create();
        $ok = EmailAccount::factory()->for($user)->create();
        EmailAccount::factory()->for($user)->needsReauth()->create();
        EmailAccount::factory()->for($user)->disconnected()->create();
        EmailAccount::factory()->create();

        $this->actingAs($user)->from('/')->post('/accounts/sync')
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Sincronização iniciada.');

        Queue::assertPushed(SyncEmailAccountJob::class, 1);
        Queue::assertPushed(SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => $job->accountId === $ok->id && $job->mode === SyncMode::Changes);
    }

    public function test_sync_all_without_accounts(): void
    {
        $this->actingAs(User::factory()->create())->from('/')->post('/accounts/sync')
            ->assertRedirect('/')
            ->assertSessionHas('error', 'Nenhuma conta conectada para sincronizar.');

        Queue::assertNothingPushed();
    }

    public function test_accounts_page_exposes_sync_progress(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->for($user)->create(['history_id' => '1', 'oldest_synced_at' => '2024-03-12 08:00:00']);

        $this->actingAs($user)->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.sync.state', 'importing_history')
            ->where('accounts.0.sync.oldest_synced_at', '2024-03-12')
        );
    }

    public function test_guests_cannot_trigger_sync(): void
    {
        $this->post('/accounts/sync')->assertRedirect('/login');
        $this->post('/accounts/1/import-history')->assertRedirect('/login');
    }
}
