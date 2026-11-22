<?php

namespace App\Services\Retention;

use App\Contracts\EmailProviderInterface;
use App\Exceptions\LabelMissing;
use App\Models\RetentionRule;

class TargetLabel
{
    public function __construct(private readonly EmailProviderInterface $provider) {}

    /**
     * Current name of the rule's target label, looked up by id so renames in
     * Gmail don't break the rule (null when the action doesn't use a label).
     *
     * @throws LabelMissing when a label the rule filters on was deleted in Gmail
     */
    public function nameFor(RetentionRule $rule): ?string
    {
        if ($rule->action !== 'add_label' && $rule->filter_type !== 'label') {
            return null;
        }

        $labels = array_column($this->provider->listLabels($rule->account), 'name', 'id');

        if ($rule->filter_type === 'label' && ! isset($labels[$rule->filter_value])) {
            throw new LabelMissing('label_missing');
        }

        return $rule->action === 'add_label' ? ($labels[$rule->target_label] ?? null) : null;
    }
}
