<?php

namespace Tests\Unit\Services\Retention;

use App\Exceptions\LabelMissing;
use App\Models\RuleExecutionLog;
use App\Services\Retention\RuleSimulator;

class RuleSimulatorTest extends RetentionTestCase
{
    private function simulator(): RuleSimulator
    {
        return $this->app->make(RuleSimulator::class);
    }

    public function test_simulation_counts_and_samples_oldest(): void
    {
        $this->promotions(25);
        $this->promotions(3, daysAgo: 2, prefix: 'recent');
        $this->promotions(2, labels: ['INBOX', 'CATEGORY_PROMOTIONS', 'STARRED'], prefix: 'starred');

        $result = $this->simulator()->simulate($this->rule(['simulated_at' => null]));

        $this->assertSame(25, $result->total);
        $this->assertFalse($result->capped);
        $this->assertCount(20, $result->sample);
        $this->assertSame(['sender' => 'Loja 24 <promo24@loja.com>', 'subject' => 'Oferta 24', 'date' => '2026-10-09T12:00:00+00:00'], $result->sample[0]);
        $this->assertSame('Oferta 5', $result->sample[19]['subject']);
        $this->assertSame([], $this->provider->sent);
        $this->assertSame([], $this->provider->modified);
    }

    public function test_simulation_caps_at_10000(): void
    {
        $this->provider->listAllOverride = array_map(fn (int $i) => "m{$i}", range(1, 10002));

        $result = $this->simulator()->simulate($this->rule());

        $this->assertSame(10000, $result->total);
        $this->assertTrue($result->capped);
        $this->assertSame(10001, $this->provider->listCalls[0]['limit']);
    }

    public function test_simulation_marks_rule_and_logs(): void
    {
        $this->promotions(4);
        $rule = $this->rule(['simulated_at' => null]);

        $this->simulator()->simulate($rule);

        $this->assertNotNull($rule->fresh()->simulated_at);
        $log = RuleExecutionLog::sole();
        $this->assertSame('simulated', $log->status);
        $this->assertSame(4, $log->affected_messages_count);
    }

    public function test_add_label_simulation_needs_existing_label(): void
    {
        $this->provider->labels = [['id' => 'Label_1', 'name' => 'Promo antigas']];
        $this->promotions(3);
        $this->promotions(2, labels: ['INBOX', 'CATEGORY_PROMOTIONS', 'Label_1'], prefix: 'done');

        $result = $this->simulator()->simulate($this->rule(['action' => 'add_label', 'target_label' => 'Label_1']));
        $this->assertSame(3, $result->total);

        $this->expectException(LabelMissing::class);
        $this->simulator()->simulate($this->rule(['action' => 'add_label', 'target_label' => 'Label_404']));
    }
}
