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
        private readonly TargetLabel $targetLabel,
    ) {}

    /**
     * @return int number of messages changed
     */
    public function execute(RetentionRule $rule): int
    {
        try {
            $query = RuleQuery::for($rule, $this->targetLabel->nameFor($rule));
        } catch (LabelMissing) {
            $rule->forceFill(['is_active' => false])->save();
            $rule->logExecution('failed', 0, 'label_missing');

            return 0;
        }

        $account = $rule->account;
        $ids = $this->provider->listAllMessageIds($account, $query->query, $query->labelIds);

        $this->provider->batchModify($account, $ids, $query->add, $query->remove);
        $this->store->applyLabelChange($account, $ids, $query->add, $query->remove);

        $rule->forceFill(['last_run_at' => now()])->save();
        $rule->logExecution('success', count($ids));

        return count($ids);
    }
}
