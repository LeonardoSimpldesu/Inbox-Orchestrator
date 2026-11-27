<?php

namespace App\Services\Retention;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\LabelMissing;
use App\Models\RetentionRule;

/**
 * Finds the messages a rule acts on. Simulation and execution both go through
 * here, so the simulated count is what a run changes.
 */
class RuleMatcher
{
    public function __construct(private readonly EmailProviderInterface $provider) {}

    /**
     * @return list<string> message ids, newest first
     *
     * @throws LabelMissing when a label the rule uses was deleted in Gmail
     */
    public function ids(RetentionRule $rule, RuleQuery $query, ?int $limit = null): array
    {
        $this->ensureLabelsExist($rule);

        $ids = $this->provider->listAllMessageIds($rule->account, $query->query, $query->labelIds, $limit);

        if ($query->excludeQuery === null || $ids === []) {
            return $ids;
        }

        $excluded = $this->provider->listAllMessageIds($rule->account, $query->excludeQuery, $query->excludeLabelIds);

        return array_values(array_diff($ids, $excluded));
    }

    private function ensureLabelsExist(RetentionRule $rule): void
    {
        $used = $rule->conditions->where('type', 'label')->pluck('value')
            ->when($rule->action === 'add_label', fn ($ids) => $ids->push($rule->target_label))
            ->all();

        if ($used === []) {
            return;
        }

        $labelIds = array_column($this->provider->listLabels($rule->account), 'id');

        if (array_diff($used, $labelIds) !== []) {
            throw new LabelMissing('label_missing');
        }
    }
}
