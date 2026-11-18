<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\RetentionRule;
use App\Models\RuleExecutionLog;
use App\Models\User;
use Tests\TestCase;

class RetentionRuleTest extends TestCase
{
    public function test_retention_rule_belongs_to_account_and_has_execution_logs(): void
    {
        $user = User::factory()->create();

        $account = EmailAccount::create([
            'user_id' => $user->id,
            'provider' => 'gmail',
            'account_email' => 'rules@gmail.com',
            'access_token' => 'tok_a',
            'refresh_token' => 'tok_r',
        ]);

        $rule = RetentionRule::create([
            'email_account_id' => $account->id,
            'name' => 'Archive old updates',
            'filter_type' => 'category',
            'filter_value' => 'CATEGORY_UPDATES',
            'retention_days' => 30,
            'action' => 'archive',
        ]);

        $log = RuleExecutionLog::create([
            'retention_rule_id' => $rule->id,
            'affected_messages_count' => 45,
            'status' => 'success',
            'executed_at' => now(),
        ]);

        // Test relations
        $this->assertEquals($account->id, $rule->account->id);
        $this->assertCount(1, $rule->executionLogs);
        $this->assertEquals(45, $rule->executionLogs->first()->affected_messages_count);
        $this->assertEquals($rule->id, $log->rule->id);
    }
}
