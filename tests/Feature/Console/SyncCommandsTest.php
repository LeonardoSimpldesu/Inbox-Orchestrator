<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncEmailAccountJob;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Sync\SyncMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.google.testing' => true]);
    }

    /**
     * @return array<string, EmailAccount>
     */
    private function accounts(): array
    {
        return [
            'recent' => EmailAccount::factory()->create(['history_id' => null]),
            'history' => EmailAccount::factory()->create(['history_id' => '1', 'oldest_synced_at' => now()->subMonths(3)]),
            'complete' => EmailAccount::factory()->create(['history_id' => '1', 'history_completed_at' => now()]),
            'disconnected' => EmailAccount::factory()->disconnected()->create(['history_id' => '1']),
            'reauth' => EmailAccount::factory()->needsReauth()->create(['history_id' => '1']),
            'expired' => EmailAccount::factory()->create(['history_id' => '1', 'authorized_at' => now()->subDays(8)]),
            'disabledUser' => EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create(['history_id' => '1']),
        ];
    }

    /**
     * @return list<int>
     */
    private function dispatchedFor(SyncMode $mode): array
    {
        return Queue::pushed(SyncEmailAccountJob::class)
            ->filter(fn (SyncEmailAccountJob $job) => $job->mode === $mode)
            ->map(fn (SyncEmailAccountJob $job) => $job->accountId)
            ->sort()->values()->all();
    }

    public function test_sync_dispatches_changes_for_eligible_accounts_only(): void
    {
        $accounts = $this->accounts();

        $this->artisan('inbox:sync')->assertExitCode(0);

        $this->assertSame(
            [$accounts['recent']->id, $accounts['history']->id, $accounts['complete']->id],
            $this->dispatchedFor(SyncMode::Changes),
        );
    }

    public function test_import_history_skips_complete_and_recent_pending(): void
    {
        $accounts = $this->accounts();

        $this->artisan('inbox:import-history')->assertExitCode(0);

        $this->assertSame([$accounts['history']->id], $this->dispatchedFor(SyncMode::History));
    }

    public function test_commands_use_constant_queries(): void
    {
        $count = function (int $accounts): array {
            DB::table('email_accounts')->delete();
            EmailAccount::factory()->count($accounts)->create(['history_id' => '1']);

            $result = [];
            foreach (['inbox:sync', 'inbox:import-history'] as $command) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->artisan($command);
                $result[$command] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            return $result;
        };

        $this->assertSame($count(3), $count(30));
    }

    public function test_schedule_registers_sync_every_five_and_history_every_ten_minutes(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [trim(str($event->command)->after('artisan')->replace(["'", '"'], '')) => $event]);

        $this->assertSame('*/5 * * * *', $events['inbox:sync']->expression);
        $this->assertSame('*/10 * * * *', $events['inbox:import-history']->expression);
        $this->assertTrue($events['inbox:sync']->withoutOverlapping);
        $this->assertTrue($events['inbox:import-history']->withoutOverlapping);
    }

    public function test_queue_retry_after_exceeds_sync_job_timeout(): void
    {
        $this->assertGreaterThan((new SyncEmailAccountJob(1))->timeout, config('queue.connections.database.retry_after'));
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER=1600', file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER=1600', file_get_contents(base_path('.env.production.example')));
    }

    public function test_docker_queue_worker_listens_to_sync_queue_with_long_timeout(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertMatchesRegularExpression('/queue:work database[^\n]*--queue=default,sync/', $compose);
        $this->assertMatchesRegularExpression('/queue:work database[^\n]*--timeout=1500/', $compose);
    }
}
