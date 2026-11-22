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

    private function rule(array $attributes = []): RetentionRule
    {
        return RetentionRule::create(array_merge([
            'email_account_id' => EmailAccount::factory()->create()->id,
            'name' => 'Promoções em 15 dias',
            'filter_type' => 'category',
            'filter_value' => 'promotions',
            'retention_days' => 15,
            'action' => 'archive',
        ], $attributes));
    }

    public function test_defaults_to_paused_and_unsimulated(): void
    {
        $rule = $this->rule();

        $this->assertFalse($rule->is_active);
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertNull($rule->fresh()->simulated_at);
        $this->assertFalse($rule->fresh()->archive_after_label);
    }

    public function test_can_activate_only_after_simulation(): void
    {
        $rule = $this->rule();
        $this->assertFalse($rule->canActivate());

        $rule->update(['simulated_at' => now()]);
        $this->assertTrue($rule->fresh()->canActivate());
    }

    public function test_category_map(): void
    {
        $this->assertSame([
            'promotions' => 'CATEGORY_PROMOTIONS',
            'updates' => 'CATEGORY_UPDATES',
            'social' => 'CATEGORY_SOCIAL',
            'forums' => 'CATEGORY_FORUMS',
        ], RetentionRule::CATEGORIES);
        $this->assertSame(['category', 'domain', 'sender', 'label'], RetentionRule::FILTER_TYPES);
        $this->assertSame(['archive', 'trash', 'add_label'], RetentionRule::ACTIONS);
    }

    public function test_active_scope(): void
    {
        $this->rule(['is_active' => true]);
        $this->rule();

        $this->assertSame(1, RetentionRule::active()->count());
    }
}
