<?php

namespace Tests\Feature\Console;

use App\Jobs\ExecuteRetentionRuleJob;
use App\Models\EmailAccount;
use App\Models\RetentionRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplyRetentionRulesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.schedule_timezone' => 'America/Sao_Paulo', 'services.google.testing' => false]);
    }

    private function rule(array $attributes = [], ?EmailAccount $account = null): RetentionRule
    {
        return RetentionRule::create(array_merge([
            'email_account_id' => ($account ?? EmailAccount::factory()->create())->id,
            'name' => 'Regra',
            'filter_type' => 'category',
            'filter_value' => 'promotions',
            'retention_days' => 15,
            'action' => 'archive',
            'is_active' => true,
            'simulated_at' => now(),
        ], $attributes));
    }

    /**
     * @return list<int>
     */
    private function dispatched(): array
    {
        return Queue::pushed(ExecuteRetentionRuleJob::class)->map(fn (ExecuteRetentionRuleJob $job) => $job->ruleId)->sort()->values()->all();
    }

    public function test_does_nothing_before_three_am(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 05:30:00', 'UTC'));
        $this->rule(['last_run_at' => '2026-11-21 06:10:00']);

        $this->artisan('inbox:apply-retention')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_runs_once_after_window_even_if_late(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 13:00:00', 'UTC'));
        $yesterday = $this->rule(['last_run_at' => '2026-11-21 06:10:00']);
        $never = $this->rule(['last_run_at' => null]);
        $this->rule(['last_run_at' => '2026-11-22 06:05:00']);

        $this->artisan('inbox:apply-retention');

        $this->assertSame([$yesterday->id, $never->id], $this->dispatched());
        Queue::assertPushed(ExecuteRetentionRuleJob::class, fn (ExecuteRetentionRuleJob $job) => $job->ruleId === $never->id && $job->accountId === $never->email_account_id);
    }

    public function test_skips_paused_and_unsyncable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 13:00:00', 'UTC'));
        $ok = $this->rule();
        $this->rule(['is_active' => false]);
        $this->rule([], EmailAccount::factory()->needsReauth()->create());
        $this->rule([], EmailAccount::factory()->disconnected()->create());
        $this->rule([], EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create());
        config(['services.google.testing' => true]);
        $this->rule([], EmailAccount::factory()->create(['authorized_at' => now()->subDays(8)]));

        $this->artisan('inbox:apply-retention')->expectsOutputToContain('1 regra(s)');

        $this->assertSame([$ok->id], $this->dispatched());
    }

    public function test_rule_failing_three_times_today_waits_for_tomorrow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 13:00:00', 'UTC'));
        $failing = $this->rule(['last_run_at' => '2026-11-21 06:10:00']);
        $flaky = $this->rule(['last_run_at' => '2026-11-21 06:10:00']);
        foreach (['2026-11-22 07:00:00', '2026-11-22 08:00:00', '2026-11-22 09:00:00'] as $time) {
            $failing->executionLogs()->create(['status' => 'failed', 'error_message' => 'http_400', 'executed_at' => $time]);
        }
        $failing->executionLogs()->create(['status' => 'failed', 'error_message' => 'http_400', 'executed_at' => '2026-11-21 23:00:00']);
        $flaky->executionLogs()->create(['status' => 'failed', 'error_message' => 'rate_limited', 'executed_at' => '2026-11-22 07:00:00']);
        $flaky->executionLogs()->create(['status' => 'simulated', 'executed_at' => '2026-11-22 07:30:00']);

        $this->artisan('inbox:apply-retention');

        $this->assertSame([$flaky->id], $this->dispatched());
    }

    public function test_command_uses_constant_queries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-22 13:00:00', 'UTC'));

        $count = function (int $rules): int {
            RetentionRule::query()->delete();
            for ($i = 0; $i < $rules; $i++) {
                $this->rule();
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->artisan('inbox:apply-retention');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(25));
    }

    public function test_scheduled_hourly_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'inbox:apply-retention'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
