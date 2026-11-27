<?php

namespace Tests\Feature\Migrations;

use App\Models\EmailAccount;
use App\Models\EmailSender;
use App\Models\RetentionRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetentionRuleConditionsMigrationTest extends TestCase
{
    private function migration(string $name = '2026_11_25_000001_create_retention_rule_conditions_table'): Migration
    {
        return require database_path("migrations/{$name}.php");
    }

    private function dropColumns(): Migration
    {
        return $this->migration('2026_11_25_000002_drop_filter_columns_from_retention_rules');
    }

    private function oldRule(EmailAccount $account, string $type, string $value, string $action, int $days = 0): int
    {
        return DB::table('retention_rules')->insertGetId([
            'email_account_id' => $account->id,
            'name' => "{$type} {$value} {$action}",
            'filter_type' => $type,
            'filter_value' => $value,
            'retention_days' => $days,
            'action' => $action,
            'target_label' => $action === 'add_label' ? 'Label_1' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_existing_rules_get_one_condition_and_the_kind_the_app_already_implied(): void
    {
        $migration = $this->migration();
        $this->dropColumns()->down();
        $migration->down();

        $account = EmailAccount::factory()->create();
        $other = EmailAccount::factory()->create();
        EmailSender::create(['email_account_id' => $account->id, 'email' => 'blocked@x.com', 'domain' => 'x.com', 'unsubscribe_status' => EmailSender::STATUS_BLOCKED]);
        EmailSender::create(['email_account_id' => $other->id, 'email' => 'clean@x.com', 'domain' => 'x.com', 'unsubscribe_status' => EmailSender::STATUS_BLOCKED]);

        $ids = [
            'category' => $this->oldRule($account, 'sender', 'cat@x.com', 'add_label'),
            'block' => $this->oldRule($account, 'sender', 'blocked@x.com', 'trash'),
            'cleanup' => $this->oldRule($account, 'sender', 'clean@x.com', 'archive'),
            'aged' => $this->oldRule($account, 'sender', 'old@x.com', 'archive', 30),
            'promotions' => $this->oldRule($account, 'category', 'promotions', 'archive', 15),
            'domain' => $this->oldRule($account, 'domain', 'x.com', 'trash'),
            'label' => $this->oldRule($account, 'label', 'Label_2', 'archive'),
        ];

        $migration->up();
        $rules = DB::table('retention_rules')->get()->keyBy('id');
        $this->dropColumns()->up();

        $kinds = DB::table('retention_rules')->pluck('kind', 'id');
        $this->assertSame('sender_category', $kinds[$ids['category']]);
        $this->assertSame('hub_block', $kinds[$ids['block']]);
        $this->assertSame('sender_cleanup', $kinds[$ids['cleanup']], 'blocked on another account does not count');
        $this->assertSame('manual', $kinds[$ids['aged']]);
        $this->assertSame('manual', $kinds[$ids['promotions']]);
        $this->assertSame('manual', $kinds[$ids['domain']]);
        $this->assertSame('manual', $kinds[$ids['label']]);

        $conditions = DB::table('retention_rule_conditions')->get()->groupBy('retention_rule_id');
        $this->assertCount(7, $conditions);
        foreach ($ids as $id) {
            $rule = $rules[$id];
            $this->assertCount(1, $conditions[$id]);
            $this->assertSame([$rule->filter_type, $rule->filter_value], [$conditions[$id][0]->type, $conditions[$id][0]->value]);
        }
    }

    public function test_the_old_filter_columns_are_gone_and_come_back_from_the_first_condition(): void
    {
        $this->assertFalse(Schema::hasColumns('retention_rules', ['filter_type']));
        $this->assertFalse(Schema::hasColumns('retention_rules', ['filter_value']));

        $rule = RetentionRule::factory()->withConditions([['sender', 'a@x.com'], ['subject', 'Oi']])->create();

        $this->dropColumns()->down();

        $this->assertSame(['sender', 'a@x.com'], [
            DB::table('retention_rules')->where('id', $rule->id)->value('filter_type'),
            DB::table('retention_rules')->where('id', $rule->id)->value('filter_value'),
        ]);
    }
}
