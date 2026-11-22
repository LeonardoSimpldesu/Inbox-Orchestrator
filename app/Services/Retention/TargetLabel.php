<?php

namespace App\Services\Retention;

use App\Contracts\EmailProviderInterface;
use App\Models\RetentionRule;

class TargetLabel
{
    public function __construct(private readonly EmailProviderInterface $provider) {}

    /**
     * Current name of the rule's target label, looked up by id so renames in
     * Gmail don't break the rule; null when it no longer exists.
     */
    public function nameFor(RetentionRule $rule): ?string
    {
        if ($rule->action !== 'add_label') {
            return null;
        }

        return collect($this->provider->listLabels($rule->account))->firstWhere('id', $rule->target_label)['name'] ?? null;
    }
}
