<?php

namespace Tests\Unit\Services\Retention;

use App\Models\EmailAccount;
use App\Models\EmailMessageMeta;
use App\Models\RuleExecutionLog;
use App\Services\Retention\RuleExecutor;
use Illuminate\Support\Facades\DB;

class RuleExecutorTest extends RetentionTestCase
{
    private function executor(): RuleExecutor
    {
        return $this->app->make(RuleExecutor::class);
    }

    public function test_archive_rule_modifies_and_mirrors_locally(): void
    {
        $this->promotions(3, store: true);
        $this->promotions(1, daysAgo: 3, store: true, prefix: 'new');
        $rule = $this->rule();

        $this->assertSame(3, $this->executor()->execute($rule));

        $this->assertEqualsCanonicalizing(['p0', 'p1', 'p2'], $this->provider->modified[0]['ids']);
        $this->assertSame(['INBOX'], $this->provider->modified[0]['remove']);
        $this->assertSame(3, EmailMessageMeta::where('is_archived', true)->count());
        $this->assertFalse(EmailMessageMeta::where('provider_message_id', 'new0')->sole()->is_archived);
        $this->assertSame('2026-11-22 12:00:00', $rule->fresh()->last_run_at->format('Y-m-d H:i:s'));
        $log = RuleExecutionLog::sole();
        $this->assertSame(['success', 3, null], [$log->status, $log->affected_messages_count, $log->error_message]);
    }

    public function test_trash_and_label_actions(): void
    {
        $this->provider->labels = [['id' => 'Label_1', 'name' => 'Lojas']];
        $this->promotions(2, store: true);

        $this->executor()->execute($this->rule(['action' => 'add_label', 'target_label' => 'Label_1', 'archive_after_label' => true]));
        $this->assertSame(['Label_1'], $this->provider->modified[0]['add']);
        $this->assertSame(['INBOX'], $this->provider->modified[0]['remove']);
        $this->assertContains('Label_1', EmailMessageMeta::first()->labels);

        $this->executor()->execute($this->rule(['filter_type' => 'domain', 'filter_value' => 'loja.com', 'retention_days' => 0, 'action' => 'trash']));
        $this->assertSame(['TRASH'], $this->provider->modified[1]['add']);
        $this->assertSame(2, EmailMessageMeta::where('is_deleted', true)->count());
    }

    public function test_zero_matches_still_logged(): void
    {
        $this->assertSame(0, $this->executor()->execute($this->rule()));

        $this->assertSame([], $this->provider->modified);
        $this->assertSame(0, RuleExecutionLog::sole()->affected_messages_count);
    }

    public function test_label_missing_pauses_rule(): void
    {
        $this->promotions(2);
        $rule = $this->rule(['action' => 'add_label', 'target_label' => 'Label_gone']);

        $this->assertSame(0, $this->executor()->execute($rule));

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame([], $this->provider->modified);
        $log = RuleExecutionLog::sole();
        $this->assertSame(['failed', 'label_missing'], [$log->status, $log->error_message]);
    }

    public function test_deleted_filter_label_pauses_rule(): void
    {
        $this->provider->labels = [['id' => 'Label_1', 'name' => 'Lojas']];
        $rule = $this->rule(['filter_type' => 'label', 'filter_value' => 'Label_gone']);

        $this->assertSame(0, $this->executor()->execute($rule));

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame([], $this->provider->listCalls);
        $this->assertSame(['failed', 'label_missing'], [RuleExecutionLog::sole()->status, RuleExecutionLog::sole()->error_message]);
    }

    public function test_execution_uses_constant_queries(): void
    {
        $count = function (int $messages, string $prefix): int {
            $this->account = EmailAccount::factory()->create();
            $this->promotions($messages, store: true, prefix: $prefix);
            $rule = $this->rule();

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->executor()->execute($rule);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(10, 'a'), $count(300, 'b'));
    }
}
