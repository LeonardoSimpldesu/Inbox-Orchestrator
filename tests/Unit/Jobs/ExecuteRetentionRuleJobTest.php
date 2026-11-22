<?php

namespace Tests\Unit\Jobs;

use App\Exceptions\ProviderUnavailable;
use App\Exceptions\ReauthorizationRequired;
use App\Jobs\ExecuteRetentionRuleJob;
use App\Models\EmailAccount;
use App\Models\RuleExecutionLog;
use App\Models\User;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\Unit\Services\Retention\RetentionTestCase;

class ExecuteRetentionRuleJobTest extends RetentionTestCase
{
    private function lastLog(): ?array
    {
        $log = RuleExecutionLog::latest('id')->first();

        return $log ? [$log->status, $log->error_message] : null;
    }

    public function test_runs_active_rule(): void
    {
        $this->promotions(2);

        dispatch_sync(new ExecuteRetentionRuleJob($this->rule()->id));

        $this->assertSame(['success', null], $this->lastLog());
        $this->assertCount(1, $this->provider->modified);
    }

    public function test_skips_paused_and_missing_rules(): void
    {
        $this->promotions(2);

        dispatch_sync(new ExecuteRetentionRuleJob($this->rule(['is_active' => false])->id));
        dispatch_sync(new ExecuteRetentionRuleJob(999999));

        $this->assertNull($this->lastLog());
        $this->assertSame([], $this->provider->modified);
    }

    public function test_unsyncable_accounts_log_failure(): void
    {
        $this->account = EmailAccount::factory()->needsReauth()->create();
        dispatch_sync(new ExecuteRetentionRuleJob($this->rule()->id));
        $this->assertSame(['failed', 'reauth_required'], $this->lastLog());

        $this->account = EmailAccount::factory()->for(User::factory()->state(['disabled_at' => now()]))->create();
        dispatch_sync(new ExecuteRetentionRuleJob($this->rule()->id));
        $this->assertSame(['failed', 'account_unavailable'], $this->lastLog());

        $this->account = EmailAccount::factory()->disconnected()->create();
        dispatch_sync(new ExecuteRetentionRuleJob($this->rule()->id));
        $this->assertSame(['failed', 'account_unavailable'], $this->lastLog());
    }

    public function test_provider_errors_log_codes(): void
    {
        $rule = $this->rule();

        $this->provider->failNext['listAllMessageIds'] = new ProviderUnavailable('rate_limited');
        dispatch_sync(new ExecuteRetentionRuleJob($rule->id));
        $this->assertSame(['failed', 'rate_limited'], $this->lastLog());

        $this->provider->failNext['listAllMessageIds'] = new ReauthorizationRequired('invalid_grant');
        dispatch_sync(new ExecuteRetentionRuleJob($rule->id));
        $this->assertSame(['failed', 'reauth_required'], $this->lastLog());
        $this->assertNull($rule->fresh()->last_run_at);
    }

    public function test_failed_hook_logs_request_failed(): void
    {
        $rule = $this->rule();

        (new ExecuteRetentionRuleJob($rule->id))->failed(new \RuntimeException('killed'));
        (new ExecuteRetentionRuleJob(999999))->failed(new \RuntimeException('gone'));

        $this->assertSame(['failed', 'request_failed'], $this->lastLog());
        $this->assertSame(1, RuleExecutionLog::count());
    }

    public function test_lock_and_queue(): void
    {
        $job = new ExecuteRetentionRuleJob(9, 5);

        $this->assertSame(10, $job->tries);
        $this->assertSame(1, $job->maxExceptions);
        [$lock, $accountLock] = $job->middleware();
        $this->assertSame('sync-account-5', $accountLock->key);
        $this->assertSame(60, $accountLock->releaseAfter);
        $this->assertSame(1600, $accountLock->expiresAfter);
        $this->assertInstanceOf(WithoutOverlapping::class, $lock);
        $this->assertSame('retention-rule-9', $lock->key);
        $this->assertNull($lock->releaseAfter);
        $this->assertSame(1600, $lock->expiresAfter);
        $this->assertSame(1500, $job->timeout);
    }
}
