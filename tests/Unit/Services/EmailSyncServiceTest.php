<?php

namespace Tests\Unit\Services;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\HistoryExpired;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\EmailSender;
use App\Services\EmailSyncService;
use App\Services\Providers\ChangePage;
use App\Services\Sync\SyncMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class EmailSyncServiceTest extends TestCase
{
    private FakeEmailProvider $provider;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
        $this->provider = new FakeEmailProvider;
        $this->app->instance(EmailProviderInterface::class, $this->provider);
        $this->account = EmailAccount::factory()->create(['history_id' => null]);
    }

    private function service(): EmailSyncService
    {
        return $this->app->make(EmailSyncService::class);
    }

    /**
     * Adds $count messages, one every $stepMinutes going back from $newest.
     */
    private function seedMailbox(int $count, string $newest = '2026-11-18 11:00:00', string $prefix = 'm', int $stepMinutes = 60): void
    {
        $start = CarbonImmutable::parse($newest);

        for ($i = 0; $i < $count; $i++) {
            $this->provider->addMessages(FakeEmailProvider::message("{$prefix}{$i}", "S{$i} <s".($i % 7).'@site.com>', $start->subMinutes($i * $stepMinutes)));
        }
    }

    private function storedIds(): array
    {
        return EmailMessageMeta::orderBy('provider_message_id')->pluck('provider_message_id')->all();
    }

    public function test_recent_imports_last_90_days_only(): void
    {
        $this->provider->addMessages(
            FakeEmailProvider::message('inside', 'a <a@x.com>', CarbonImmutable::parse('2026-08-21 12:00:00')),
            FakeEmailProvider::message('outside', 'a <a@x.com>', CarbonImmutable::parse('2026-08-19 12:00:00')),
        );

        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame(['inside'], $this->storedIds());
        $this->assertSame('after:'.CarbonImmutable::parse('2026-08-20 12:00:00')->timestamp, $this->provider->listCalls[0]['query']);
        $this->assertSame('importing_history', $this->account->fresh()->syncState());
    }

    public function test_recent_stops_at_5000_messages(): void
    {
        $this->seedMailbox(5050, prefix: 'r', stepMinutes: 10);

        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame(5000, EmailMessageMeta::count());
        $this->assertNotNull($this->account->fresh()->history_id);
    }

    public function test_recent_captures_history_id_before_listing(): void
    {
        $this->seedMailbox(3);
        $this->provider->historyId = '500';
        $this->provider->beforeCall = function (string $method) {
            if ($method === 'listMessageIds') {
                $this->provider->historyId = '999';
            }
        };

        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame('500', $this->account->fresh()->history_id);
    }

    public function test_recent_with_empty_window_sets_oldest_to_window_start(): void
    {
        $this->service()->run($this->account, SyncMode::Recent);

        $account = $this->account->fresh();
        $this->assertSame('2026-08-20 12:00:00', $account->oldest_synced_at->format('Y-m-d H:i:s'));
        $this->assertSame('1000', $account->history_id);
    }

    public function test_changes_applies_changes_and_advances_history_id(): void
    {
        $this->seedMailbox(2);
        $this->service()->run($this->account, SyncMode::Recent);
        $oldest = $this->account->fresh()->oldest_synced_at;

        $this->provider->addMessages(
            FakeEmailProvider::message('new', 'N <n@x.com>', CarbonImmutable::parse('2026-11-18 11:30:00')),
            FakeEmailProvider::message('ancient', 'Old <old@x.com>', CarbonImmutable::parse('2019-01-01 00:00:00')),
            FakeEmailProvider::message('m0', 'S0 <s0@site.com>', CarbonImmutable::parse('2026-11-18 11:00:00'), ['labelIds' => ['TRASH']]),
        );
        $this->provider->changePages = [
            new ChangePage(['new', 'm0'], [], '1100', 'p2'),
            new ChangePage(['ancient'], ['m1'], '1100', null),
        ];

        $this->service()->run($this->account->fresh(), SyncMode::Changes);

        $account = $this->account->fresh();
        $this->assertSame(['ancient', 'm0', 'new'], $this->storedIds());
        $this->assertTrue(EmailMessageMeta::where('provider_message_id', 'm0')->sole()->is_deleted);
        $this->assertSame('1100', $account->history_id);
        $this->assertEquals($oldest, $account->oldest_synced_at);
        $this->assertSame('2026-11-18 12:00:00', $account->last_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_changes_without_history_id_runs_recent(): void
    {
        $this->seedMailbox(2);

        $this->service()->run($this->account, SyncMode::Changes);

        $this->assertSame(2, EmailMessageMeta::count());
        $this->assertSame('1000', $this->account->fresh()->history_id);
    }

    public function test_expired_history_reimports_recent_without_duplicates(): void
    {
        $this->seedMailbox(3);
        $this->service()->run($this->account, SyncMode::Recent);
        $this->provider->historyId = '7777';
        $this->provider->changePages = [new HistoryExpired('history_expired')];

        $this->service()->run($this->account->fresh(), SyncMode::Changes);

        $this->assertSame(3, EmailMessageMeta::count());
        $this->assertSame(3, (int) EmailSender::sum('total_messages_received'));
        $this->assertSame('7777', $this->account->fresh()->history_id);
    }

    public function test_history_continues_before_oldest_and_stops_at_500(): void
    {
        $this->account->update(['history_id' => '1', 'oldest_synced_at' => '2026-01-01 00:00:00']);
        $this->seedMailbox(600, '2025-12-31 23:00:00', 'h');
        $this->provider->addMessages(FakeEmailProvider::message('newer', 'a <a@x.com>', CarbonImmutable::parse('2026-03-01')));

        $this->service()->run($this->account, SyncMode::History);

        $account = $this->account->fresh();
        $this->assertSame(500, EmailMessageMeta::count());
        $this->assertFalse(EmailMessageMeta::where('provider_message_id', 'newer')->exists());
        $this->assertSame('before:'.(CarbonImmutable::parse('2026-01-01')->timestamp + 1), $this->provider->listCalls[0]['query']);
        $this->assertNull($account->history_completed_at);
        $this->assertTrue($account->oldest_synced_at->lessThan(CarbonImmutable::parse('2026-01-01')));
    }

    public function test_history_marks_complete_when_exhausted(): void
    {
        $this->account->update(['history_id' => '1', 'oldest_synced_at' => '2026-01-01 00:00:00']);
        $this->seedMailbox(30, '2025-12-31 23:00:00', 'h');

        $this->service()->run($this->account, SyncMode::History);
        $this->assertSame('complete', $this->account->fresh()->syncState());

        $empty = EmailAccount::factory()->create(['history_id' => '1', 'oldest_synced_at' => '2000-01-01 00:00:00']);
        $this->service()->run($empty, SyncMode::History);
        $this->assertSame('complete', $empty->fresh()->syncState());
    }

    public function test_history_does_nothing_before_recent(): void
    {
        $this->seedMailbox(3);

        $this->service()->run($this->account, SyncMode::HistoryNow);

        $this->assertSame([], $this->provider->listCalls);
        $this->assertSame(0, EmailMessageMeta::count());
    }

    public function test_time_budget_stops_between_batches(): void
    {
        $this->account->update(['history_id' => '1', 'oldest_synced_at' => '2026-01-01 00:00:00']);
        $this->seedMailbox(1000, '2025-12-31 23:00:00', 'h');
        $this->provider->beforeCall = function (string $method) {
            if ($method === 'fetchMetadata') {
                $this->travel(11)->minutes();
            }
        };

        $this->service()->run($this->account, SyncMode::HistoryNow);

        $this->assertSame(200, EmailMessageMeta::count());
        $this->assertNull($this->account->fresh()->history_completed_at);
    }

    public function test_stops_when_account_disconnected_mid_run(): void
    {
        $this->seedMailbox(250);
        $this->provider->beforeCall = function (string $method) {
            if ($method === 'fetchMetadata') {
                EmailAccount::whereKey($this->account->id)->update(['deleted_at' => now()]);
            }
        };

        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame(100, EmailMessageMeta::count());
        $this->assertNull($this->account->fresh()?->history_id);
    }

    public function test_history_stops_when_account_needs_reauth_mid_run(): void
    {
        $this->account->update(['history_id' => '1', 'oldest_synced_at' => '2026-01-01 00:00:00']);
        $this->seedMailbox(30, '2025-12-31 23:00:00', 'h');
        $this->provider->beforeCall = function (string $method) {
            if ($method === 'fetchMetadata') {
                EmailAccount::whereKey($this->account->id)->update(['reauth_required_at' => now()]);
            }
        };

        $this->service()->run($this->account, SyncMode::History);

        $account = $this->account->fresh();
        $this->assertSame(30, EmailMessageMeta::count());
        $this->assertNull($account->history_completed_at);
        $this->assertNull($account->last_synced_at);
    }

    public function test_reauthorization_required_stops_quietly(): void
    {
        $this->seedMailbox(2);
        $this->provider->failNext['listMessageIds'] = new ReauthorizationRequired('invalid_grant');

        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame(0, EmailMessageMeta::count());
        $this->assertNull($this->account->fresh()->last_synced_at);
    }

    public function test_provider_unavailable_stops_and_logs_without_secrets(): void
    {
        Log::spy();
        $this->seedMailbox(150);
        $calls = 0;
        $this->provider->beforeCall = function (string $method) use (&$calls) {
            if ($method === 'fetchMetadata' && ++$calls === 2) {
                throw new ProviderUnavailable('http_429');
            }
        };

        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame(100, EmailMessageMeta::count());
        $this->assertNull($this->account->fresh()->history_id);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $this->assertSame(['account_id' => $this->account->id, 'mode' => 'recent', 'error' => 'http_429'], $context);

            return true;
        });
    }

    public function test_last_synced_at_updated_on_success(): void
    {
        $this->service()->run($this->account, SyncMode::Recent);

        $this->assertSame('2026-11-18 12:00:00', $this->account->fresh()->last_synced_at->format('Y-m-d H:i:s'));
    }

    public function test_changes_stopped_by_budget_keeps_previous_history_id(): void
    {
        $this->account->update(['history_id' => '50', 'oldest_synced_at' => '2026-01-01 00:00:00']);
        $this->provider->addMessages(FakeEmailProvider::message('n1', 'a <a@x.com>', CarbonImmutable::parse('2026-11-18 11:00')));
        $this->provider->changePages = [new ChangePage(['n1'], [], '60', 'more'), new ChangePage([], [], '60', null)];
        $this->provider->beforeCall = function (string $method) {
            if ($method === 'listChanges') {
                $this->travel(21)->minutes();
            }
        };

        $this->service()->run($this->account, SyncMode::Changes);

        $this->assertSame('50', $this->account->fresh()->history_id);
        $this->assertSame(1, EmailMessageMeta::count());
    }

    public function test_modes_define_their_budgets(): void
    {
        $this->assertSame(5000, SyncMode::Recent->messageLimit());
        $this->assertSame(500, SyncMode::History->messageLimit());
        $this->assertNull(SyncMode::Changes->messageLimit());
        $this->assertNull(SyncMode::HistoryNow->messageLimit());

        foreach (SyncMode::cases() as $mode) {
            $this->assertSame(1200, $mode->timeLimitSeconds());
        }

        $this->assertTrue(SyncMode::Changes->isScheduled());
        $this->assertTrue(SyncMode::History->isScheduled());
        $this->assertFalse(SyncMode::Recent->isScheduled());
        $this->assertFalse(SyncMode::HistoryNow->isScheduled());
    }

    public function test_expired_history_restarts_the_older_mail_import(): void
    {
        $this->account->update(['history_id' => '1', 'oldest_synced_at' => '2019-01-01 00:00:00', 'history_completed_at' => '2026-10-01 00:00:00']);
        $this->seedMailbox(2);
        $this->provider->changePages = [new HistoryExpired('history_expired')];

        $this->service()->run($this->account, SyncMode::Changes);

        $account = $this->account->fresh();
        $this->assertNull($account->history_completed_at);
        $this->assertTrue($account->oldest_synced_at->greaterThan(CarbonImmutable::parse('2026-08-19')));
        $this->assertSame('importing_history', $account->syncState());
    }

    public function test_manual_history_import_clears_its_flag(): void
    {
        $this->account->update(['history_id' => '1', 'oldest_synced_at' => '2026-01-01 00:00:00', 'history_import_requested_at' => now()]);

        $this->service()->run($this->account, SyncMode::HistoryNow);
        $this->assertNull($this->account->fresh()->history_import_requested_at);

        $this->account->update(['history_import_requested_at' => now()]);
        $this->provider->failNext['listMessageIds'] = new ProviderUnavailable('rate_limited');
        $this->service()->run($this->account->fresh(), SyncMode::HistoryNow);
        $this->assertNull($this->account->fresh()->history_import_requested_at);
    }
}
