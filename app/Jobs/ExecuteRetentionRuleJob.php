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

    public int $tries = 1;

    public function __construct(public readonly int $ruleId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("retention-rule-{$this->ruleId}"))->dontRelease()];
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
