<?php

namespace Tests\Unit\Jobs;

use App\Contracts\EmailProviderInterface;
use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\User;
use App\Services\Sync\SyncMode;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class SyncEmailAccountJobTest extends TestCase
{
    private FakeEmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->provider->addMessages(FakeEmailProvider::message('m1', 'a <a@x.com>', now()->subDay()->toImmutable()));
    }

    public function test_runs_for_connected_account(): void
    {
        $account = EmailAccount::factory()->create();

        $this->assertTrue((new SyncEmailAccountJob($account->id))->shouldRun());
    }

    public function test_skips_disconnected_account(): void
    {
        $this->assertFalse((new SyncEmailAccountJob(EmailAccount::factory()->disconnected()->create()->id))->shouldRun());
    }

    public function test_skips_disabled_user(): void
    {
        $account = EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create();

        $this->assertFalse((new SyncEmailAccountJob($account->id))->shouldRun());
    }

    public function test_skips_account_needing_reauth(): void
    {
        $this->assertFalse((new SyncEmailAccountJob(EmailAccount::factory()->needsReauth()->create()->id))->shouldRun());
    }

    public function test_skips_missing_account(): void
    {
        $this->assertFalse((new SyncEmailAccountJob(999))->shouldRun());
    }

    public function test_job_runs_service_with_mode(): void
    {
        $account = EmailAccount::factory()->create(['history_id' => null]);

        dispatch_sync(new SyncEmailAccountJob($account->id));

        $this->assertSame(1, EmailMessageMeta::count());
        $this->assertSame('1000', $account->fresh()->history_id);
    }

    public function test_skipped_job_does_not_call_provider(): void
    {
        $account = EmailAccount::factory()->needsReauth()->create(['history_id' => null]);

        dispatch_sync(new SyncEmailAccountJob($account->id, SyncMode::Recent));

        $this->assertSame([], $this->provider->listCalls);
    }

    public function test_job_uses_sync_queue_and_lock(): void
    {
        $scheduled = new SyncEmailAccountJob(7, SyncMode::Changes);
        $manual = new SyncEmailAccountJob(7, SyncMode::HistoryNow);

        $this->assertSame('sync', $scheduled->queue);
        $this->assertSame(1500, $scheduled->timeout);
        $this->assertSame(1, $scheduled->maxExceptions);
        $this->assertSame(1, $scheduled->tries());
        $this->assertSame(5, $manual->tries());

        [$lock] = $scheduled->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $lock);
        $this->assertSame('sync-account-7', $lock->key);
        $this->assertNull($lock->releaseAfter);
        $this->assertSame(1600, $lock->expiresAfter);
        $this->assertSame(60, $manual->middleware()[0]->releaseAfter);
    }

    public function test_payload_has_no_tokens(): void
    {
        $account = EmailAccount::factory()->create(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh']);

        $payload = serialize(new SyncEmailAccountJob($account->id, SyncMode::Changes));

        $this->assertStringNotContainsString('secret-access', $payload);
        $this->assertStringNotContainsString('secret-refresh', $payload);
    }
}
