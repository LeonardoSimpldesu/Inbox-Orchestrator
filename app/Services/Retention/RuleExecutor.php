<?php

namespace App\Services\Retention;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\LabelMissing;
use App\Models\RetentionRule;
use App\Services\Sync\MessageStore;

class RuleExecutor
{
    public function __construct(
        private readonly EmailProviderInterface $provider,
        private readonly MessageStore $store,
        private readonly RuleMatcher $matcher,
    ) {}

    /**
     * @return int number of messages changed
     */
    public function execute(RetentionRule $rule): int
    {
        $query = RuleQuery::for($rule);

        try {
            $ids = $this->matcher->ids($rule, $query);
        } catch (LabelMissing) {
            $rule->forceFill(['is_active' => false])->save();
            $rule->logExecution('failed', 0, 'label_missing');

            return 0;
        }

        $account = $rule->account;

        $this->provider->batchModify($account, $ids, $query->add, $query->remove);
        $this->store->applyLabelChange($account, $ids, $query->add, $query->remove);

        $rule->forceFill(['last_run_at' => now()])->save();
        $rule->logExecution('success', count($ids));

        return count($ids);
    }
}
