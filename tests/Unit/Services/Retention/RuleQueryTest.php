<?php

namespace Tests\Unit\Services\Retention;

use App\Models\RetentionRule;
use App\Services\Retention\RuleQuery;
use PHPUnit\Framework\TestCase;

class RuleQueryTest extends TestCase
{
    private function rule(array $attributes): RetentionRule
    {
        return new RetentionRule(array_merge(['retention_days' => 0, 'archive_after_label' => false], $attributes));
    }

    public function test_category_archive(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'category', 'filter_value' => 'promotions', 'retention_days' => 15, 'action' => 'archive']));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:15d in:inbox', $query->query);
        $this->assertSame(['CATEGORY_PROMOTIONS'], $query->labelIds);
        $this->assertSame([], $query->add);
        $this->assertSame(['INBOX'], $query->remove);
    }

    public function test_domain_trash_any_age(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'domain', 'filter_value' => 'amazon.com.br', 'action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts from:@amazon.com.br', $query->query);
        $this->assertSame([], $query->labelIds);
        $this->assertSame(['TRASH'], $query->add);
        $this->assertSame([], $query->remove);
    }

    public function test_sender_filter(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'sender', 'filter_value' => 'news@site.com', 'retention_days' => 30, 'action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:30d from:news@site.com', $query->query);
    }

    public function test_label_filter_uses_label_id(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'label', 'filter_value' => 'Label_42', 'retention_days' => 90, 'action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:90d', $query->query);
        $this->assertSame(['Label_42'], $query->labelIds);
    }

    public function test_add_label_excludes_already_labeled_by_id(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'domain', 'filter_value' => 'nubank.com.br', 'action' => 'add_label', 'target_label' => 'Label_7']));

        $this->assertSame('-is:starred -in:sent -in:drafts from:@nubank.com.br', $query->query);
        $this->assertSame('-is:starred -in:sent -in:drafts from:@nubank.com.br', $query->excludeQuery);
        $this->assertSame(['Label_7'], $query->excludeLabelIds);
        $this->assertSame(['Label_7'], $query->add);
        $this->assertSame([], $query->remove);
    }

    public function test_add_label_and_archive(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'label', 'filter_value' => 'Label_1', 'action' => 'add_label', 'target_label' => 'Label_7', 'archive_after_label' => true]));

        $this->assertSame('-is:starred -in:sent -in:drafts', $query->query);
        $this->assertSame('-is:starred -in:sent -in:drafts -in:inbox', $query->excludeQuery);
        $this->assertSame(['Label_1', 'Label_7'], $query->excludeLabelIds);
        $this->assertSame(['Label_7'], $query->add);
        $this->assertSame(['INBOX'], $query->remove);
    }

    public function test_other_actions_have_no_exclusion(): void
    {
        $query = RuleQuery::for($this->rule(['filter_type' => 'category', 'filter_value' => 'promotions', 'action' => 'trash']));

        $this->assertNull($query->excludeQuery);
        $this->assertSame([], $query->excludeLabelIds);
    }

    public function test_unknown_category_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RuleQuery::for($this->rule(['filter_type' => 'category', 'filter_value' => 'primary', 'action' => 'archive']));
    }
}
