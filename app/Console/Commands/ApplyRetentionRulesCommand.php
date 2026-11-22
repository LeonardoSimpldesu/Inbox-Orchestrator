<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteRetentionRuleJob;
use App\Models\RetentionRule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ApplyRetentionRulesCommand extends Command
{
    protected $signature = 'inbox:apply-retention';

    protected $description = 'Queue active retention rules that have not run since 03:00 today';

    private const DAILY_HOUR = 3;

    /** Failed runs allowed per day before the rule waits for tomorrow's window. */
    private const DAILY_ATTEMPTS = 3;

    /**
     * Runs hourly: rules execute once a day, in the first run after 03:00 (user
     * time zone), so a machine that was asleep at 03:00 still runs them that day.
     */
    public function handle(): int
    {
        $timezone = (string) config('app.schedule_timezone');
        $windowStart = CarbonImmutable::now($timezone)->setTime(self::DAILY_HOUR, 0);

        if (CarbonImmutable::now($timezone)->lessThan($windowStart)) {
            $this->info('Antes das 03:00: nenhuma regra executada.');

            return self::SUCCESS;
        }

        $rules = RetentionRule::active()
            ->whereHas('account', fn (Builder $account) => $account->syncable())
            ->where(fn (Builder $query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<', $windowStart->utc()))
            ->whereHas('executionLogs', fn (Builder $logs) => $logs
                ->where('status', 'failed')
                ->where('executed_at', '>=', $windowStart->utc()), '<', self::DAILY_ATTEMPTS)
            ->with('account')
            ->get()
            ->reject(fn (RetentionRule $rule) => $rule->account->needsReauth());

        $rules->each(fn (RetentionRule $rule) => ExecuteRetentionRuleJob::dispatch($rule->id, $rule->email_account_id));

        $this->info("Execução enfileirada para {$rules->count()} regra(s).");

        return self::SUCCESS;
    }
}
