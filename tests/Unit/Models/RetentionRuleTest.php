<?php

namespace Tests\Unit\Models;

use App\Models\EmailAccount;
use App\Models\RetentionRule;
use App\Models\RuleExecutionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        $rule = RetentionRule::factory()->withConditions([['category', 'CATEGORY_UPDATES']])->create([
            'email_account_id' => $account->id,
            'name' => 'Archive old updates',
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
        $this->assertSame(['archive', 'trash', 'add_label'], RetentionRule::ACTIONS);
    }

    public function test_active_scope(): void
    {
        $this->rule(['is_active' => true]);
        $this->rule();

        $this->assertSame(1, RetentionRule::active()->count());
    }

    public function test_kinds_and_condition_types(): void
    {
        $this->assertSame(['manual', 'sender_category', 'sender_cleanup', 'hub_block'], RetentionRule::KINDS);
        $this->assertSame(['sender_category', 'sender_cleanup', 'hub_block'], RetentionRule::MANAGED_KINDS);
        $this->assertSame(['category', 'label', 'domain', 'sender', 'subject'], RetentionRule::CONDITION_TYPES);
        $this->assertSame('manual', $this->rule()->fresh()->kind);
        $this->assertFalse($this->rule()->isManaged());
        $this->assertTrue($this->rule(['kind' => 'hub_block'])->isManaged());
    }

    public function test_replace_conditions_swaps_the_list_in_order(): void
    {
        $rule = $this->rule();
        $rule->replaceConditions([['type' => 'category', 'value' => 'promotions']]);
        $rule->replaceConditions([['type' => 'subject', 'value' => 'Bem-vindo'], ['type' => 'subject', 'value' => 'Welcome']]);

        $this->assertSame(
            [['type' => 'subject', 'value' => 'Bem-vindo'], ['type' => 'subject', 'value' => 'Welcome']],
            $rule->fresh()->conditions->map->only(['type', 'value'])->all(),
        );
        $this->assertSame(2, DB::table('retention_rule_conditions')->count());
    }

    public function test_deleting_a_rule_deletes_its_conditions(): void
    {
        $rule = $this->rule();
        $rule->replaceConditions([['type' => 'domain', 'value' => 'x.com']]);

        $rule->delete();

        $this->assertSame(0, DB::table('retention_rule_conditions')->count());
    }

    public function test_insert_with_conditions_pairs_each_rule_with_its_conditions_in_constant_queries(): void
    {
        $account = EmailAccount::factory()->create();
        $row = fn (int $i) => [
            'email_account_id' => $account->id, 'name' => "Regra {$i}", 'kind' => 'sender_cleanup',
            'retention_days' => 0, 'action' => 'trash', 'created_at' => now(), 'updated_at' => now(),
        ];
        // An older rule of the same kind must keep its own conditions.
        RetentionRule::insertWithConditions([$row(0)], [[['type' => 'sender', 'value' => 's0@x.com']]]);

        $count = function (int $n) use ($row): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            RetentionRule::insertWithConditions(
                array_map($row, range(1, $n)),
                array_map(fn (int $i) => [['type' => 'sender', 'value' => "s{$i}@x.com"], ['type' => 'subject', 'value' => "Oi {$i}"]], range(1, $n)),
            );
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->reject(fn (array $q) => str_contains($q['query'], 'savepoint'))->count();
        };

        $this->assertSame($count(1), $count(50));

        foreach (RetentionRule::with('conditions')->get() as $rule) {
            $i = (int) substr($rule->name, 6);
            $expected = [['type' => 'sender', 'value' => "s{$i}@x.com"]];
            if ($i > 0) {
                $expected[] = ['type' => 'subject', 'value' => "Oi {$i}"];
            }
            $this->assertSame($expected, $rule->conditions->map->only(['type', 'value'])->all());
        }
        $this->assertSame(52, RetentionRule::count());
    }

    public function test_insert_with_conditions_refuses_to_guess_when_an_older_rule_has_no_conditions(): void
    {
        $account = EmailAccount::factory()->create();
        $orphan = RetentionRule::create(['email_account_id' => $account->id, 'name' => 'Sem condições', 'kind' => 'sender_cleanup', 'retention_days' => 0, 'action' => 'trash']);

        try {
            RetentionRule::insertWithConditions(
                [['email_account_id' => $account->id, 'name' => 'Nova', 'kind' => 'sender_cleanup', 'retention_days' => 0, 'action' => 'trash']],
                [[['type' => 'sender', 'value' => 'a@x.com']]],
            );
            $this->fail('Expected a LogicException.');
        } catch (\LogicException) {
        }

        $this->assertSame([$orphan->id], RetentionRule::pluck('id')->all());
        $this->assertSame(0, DB::table('retention_rule_conditions')->count());
    }
}
