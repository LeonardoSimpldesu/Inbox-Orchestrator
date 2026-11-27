<?php

namespace Tests\Unit\Services\Retention;

use App\Models\RetentionRule;
use App\Models\RetentionRuleCondition;
use App\Services\Retention\RuleQuery;
use PHPUnit\Framework\TestCase;

class RuleQueryTest extends TestCase
{
    /**
     * @param  list<array{0: string, 1: string}>  $conditions  [type, value] pairs
     */
    private function rule(array $conditions, array $attributes): RetentionRule
    {
        $rule = new RetentionRule(array_merge(['retention_days' => 0, 'archive_after_label' => false], $attributes));

        return $rule->setRelation('conditions', collect($conditions)->map(
            fn (array $c) => new RetentionRuleCondition(['type' => $c[0], 'value' => $c[1]]),
        ));
    }

    public function test_category_archive(): void
    {
        $query = RuleQuery::for($this->rule([['category', 'promotions']], ['retention_days' => 15, 'action' => 'archive']));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:15d in:inbox', $query->query);
        $this->assertSame(['CATEGORY_PROMOTIONS'], $query->labelIds);
        $this->assertSame([], $query->add);
        $this->assertSame(['INBOX'], $query->remove);
    }

    public function test_domain_trash_any_age(): void
    {
        $query = RuleQuery::for($this->rule([['domain', 'amazon.com.br']], ['action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts from:@amazon.com.br', $query->query);
        $this->assertSame([], $query->labelIds);
        $this->assertSame(['TRASH'], $query->add);
        $this->assertSame([], $query->remove);
    }

    public function test_sender_filter(): void
    {
        $query = RuleQuery::for($this->rule([['sender', 'news@site.com']], ['retention_days' => 30, 'action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:30d from:"news@site.com"', $query->query);
    }

    public function test_label_filter_uses_label_id(): void
    {
        $query = RuleQuery::for($this->rule([['label', 'Label_42']], ['retention_days' => 90, 'action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:90d', $query->query);
        $this->assertSame(['Label_42'], $query->labelIds);
    }

    public function test_add_label_excludes_already_labeled_by_id(): void
    {
        $query = RuleQuery::for($this->rule([['domain', 'nubank.com.br']], ['action' => 'add_label', 'target_label' => 'Label_7']));

        $this->assertSame('-is:starred -in:sent -in:drafts from:@nubank.com.br', $query->query);
        $this->assertSame('-is:starred -in:sent -in:drafts from:@nubank.com.br', $query->excludeQuery);
        $this->assertSame(['Label_7'], $query->excludeLabelIds);
        $this->assertSame(['Label_7'], $query->add);
        $this->assertSame([], $query->remove);
    }

    public function test_add_label_and_archive(): void
    {
        $query = RuleQuery::for($this->rule([['label', 'Label_1']], ['action' => 'add_label', 'target_label' => 'Label_7', 'archive_after_label' => true]));

        $this->assertSame('-is:starred -in:sent -in:drafts', $query->query);
        $this->assertSame('-is:starred -in:sent -in:drafts -in:inbox', $query->excludeQuery);
        $this->assertSame(['Label_1', 'Label_7'], $query->excludeLabelIds);
        $this->assertSame(['Label_7'], $query->add);
        $this->assertSame(['INBOX'], $query->remove);
    }

    public function test_other_actions_have_no_exclusion(): void
    {
        $query = RuleQuery::for($this->rule([['category', 'promotions']], ['action' => 'trash']));

        $this->assertNull($query->excludeQuery);
        $this->assertSame([], $query->excludeLabelIds);
    }

    public function test_unknown_category_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RuleQuery::for($this->rule([['category', 'primary']], ['action' => 'archive']));
    }

    public function test_single_subject_is_quoted(): void
    {
        $query = RuleQuery::for($this->rule([['subject', 'Bem-vindo']], ['action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts subject:"Bem-vindo"', $query->query);
    }

    public function test_subjects_are_alternatives(): void
    {
        $query = RuleQuery::for($this->rule([['subject', 'Bem-vindo'], ['subject', 'Welcome']], ['action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts subject:("Bem-vindo" OR "Welcome")', $query->query);
    }

    public function test_different_types_are_all_required(): void
    {
        $query = RuleQuery::for($this->rule([['subject', 'Alerta de segurança'], ['sender', 'no-reply@accounts.google.com']], ['action' => 'archive']));

        $this->assertSame('-is:starred -in:sent -in:drafts from:"no-reply@accounts.google.com" subject:"Alerta de segurança" in:inbox', $query->query);
    }

    public function test_several_senders_or_domains_are_grouped(): void
    {
        $query = RuleQuery::for($this->rule([['domain', 'a.com'], ['domain', 'b.com'], ['sender', 'x@c.com'], ['sender', 'y@c.com']], ['action' => 'trash', 'retention_days' => 7]));

        $this->assertSame('-is:starred -in:sent -in:drafts older_than:7d {from:@a.com from:@b.com} {from:"x@c.com" from:"y@c.com"}', $query->query);
        $this->assertSame([], $query->labelIds);
    }

    public function test_several_categories_become_a_search_group(): void
    {
        $query = RuleQuery::for($this->rule([['category', 'promotions'], ['category', 'updates']], ['action' => 'trash']));

        $this->assertSame('-is:starred -in:sent -in:drafts {category:promotions category:updates}', $query->query);
        $this->assertSame([], $query->labelIds);
    }

    public function test_category_and_label_are_both_label_ids(): void
    {
        $query = RuleQuery::for($this->rule([['label', 'Label_1'], ['category', 'promotions']], ['action' => 'add_label', 'target_label' => 'Label_7']));

        $this->assertSame(['CATEGORY_PROMOTIONS', 'Label_1'], $query->labelIds);
        $this->assertSame(['CATEGORY_PROMOTIONS', 'Label_1', 'Label_7'], $query->excludeLabelIds);
        $this->assertStringNotContainsString('label:', $query->query);
    }

    public function test_a_rule_without_conditions_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RuleQuery::for($this->rule([], ['action' => 'trash']));
    }
}
