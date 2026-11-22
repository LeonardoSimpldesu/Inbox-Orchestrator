<?php

namespace App\Jobs;

use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Models\RetentionRule;
use App\Services\Retention\RuleExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExecuteRetentionRuleJob implements ShouldQueue
{
    use Queueable;

    /** Retries only cover waiting for the account lock; a real failure stops at the first exception. */
    public int $tries = 10;

    public int $maxExceptions = 1;

    /** Must stay below the queue's retry_after (DB_QUEUE_RETRY_AFTER). */
    public int $timeout = 1500;

    public function __construct(
        public readonly int $ruleId,
        public readonly ?int $accountId = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $middleware = [(new WithoutOverlapping("retention-rule-{$this->ruleId}"))->expireAfter(1600)->dontRelease()];

        // Shares the account's sync lock so a run never overwrites labels a sync is saving.
        if ($this->accountId !== null) {
            $middleware[] = (new WithoutOverlapping("sync-account-{$this->accountId}"))->expireAfter(1600)->releaseAfter(60);
        }

        return $middleware;
    }

    public function handle(RuleExecutor $executor): void
    {
        $rule = RetentionRule::with('account.user')->find($this->ruleId);

        if ($rule === null || ! $rule->is_active) {
            return;
        }

        $account = $rule->account;

        if ($account === null || $account->user->isDisabled()) {
            $rule->logExecution('failed', 0, 'account_unavailable');

            return;
        }

        if ($account->needsReauth()) {
            $rule->logExecution('failed', 0, 'reauth_required');

            return;
        }

        try {
            $executor->execute($rule);
        } catch (ProviderUnavailable $e) {
            $rule->logExecution('failed', 0, $e->getMessage());
        } catch (ReauthorizationRequired) {
            $rule->logExecution('failed', 0, 'reauth_required');
        }
    }

    public function failed(Throwable $exception): void
    {
        RetentionRule::find($this->ruleId)?->logExecution('failed', 0, 'request_failed');
    }
}
