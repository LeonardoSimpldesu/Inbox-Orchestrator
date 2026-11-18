<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\User;
use Tests\TestCase;

class SyncEmailAccountJobTest extends TestCase
{
    public function test_runs_for_connected_account(): void
    {
        $account = EmailAccount::factory()->create();

        $this->assertTrue((new SyncEmailAccountJob($account->id))->shouldRun());
    }

    public function test_skips_disconnected_account(): void
    {
        $account = EmailAccount::factory()->disconnected()->create();

        $this->assertFalse((new SyncEmailAccountJob($account->id))->shouldRun());
    }

    public function test_skips_disabled_user(): void
    {
        $account = EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create();

        $this->assertFalse((new SyncEmailAccountJob($account->id))->shouldRun());
    }

    public function test_skips_account_needing_reauth(): void
    {
        $account = EmailAccount::factory()->needsReauth()->create();

        $this->assertFalse((new SyncEmailAccountJob($account->id))->shouldRun());
    }

    public function test_skips_missing_account(): void
    {
        $this->assertFalse((new SyncEmailAccountJob(999))->shouldRun());
    }

    public function test_handle_runs_without_touching_skipped_accounts(): void
    {
        $account = EmailAccount::factory()->needsReauth()->create();

        (new SyncEmailAccountJob($account->id))->handle();
        dispatch_sync(new SyncEmailAccountJob(EmailAccount::factory()->create()->id));

        $this->assertNull($account->fresh()->last_synced_at);
    }

    public function test_payload_has_no_tokens(): void
    {
        $account = EmailAccount::factory()->create(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh']);

        $payload = serialize(new SyncEmailAccountJob($account->id));

        $this->assertStringNotContainsString('secret-access', $payload);
        $this->assertStringNotContainsString('secret-refresh', $payload);
    }
}
