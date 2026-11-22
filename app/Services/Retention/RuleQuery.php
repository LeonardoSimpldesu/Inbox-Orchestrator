<?php

namespace App\Services\Retention;

use App\Exceptions\LabelMissing;
use App\Models\RetentionRule;
use InvalidArgumentException;

/**
 * Translates a rule into a Gmail search plus the label changes to apply.
 * Simulation and execution share it, so the simulated count is what runs.
 */
final readonly class RuleQuery
{
    /** Mail a rule must never touch, whatever its filter. */
    private const ALWAYS_EXCLUDED = '-is:starred -in:sent -in:drafts';

    /**
     * @param  list<string>  $labelIds
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    private function __construct(
        public string $query,
        public array $labelIds,
        public array $add,
        public array $remove,
    ) {}

    /**
     * @param  string|null  $targetLabelName  current name of the rule's target label (add_label only)
     */
    public static function for(RetentionRule $rule, ?string $targetLabelName = null): self
    {
        $terms = [self::ALWAYS_EXCLUDED];
        $labelIds = [];

        if ($rule->retention_days > 0) {
            $terms[] = "older_than:{$rule->retention_days}d";
        }

        match ($rule->filter_type) {
            'category' => $labelIds[] = RetentionRule::CATEGORIES[$rule->filter_value]
                ?? throw new InvalidArgumentException('Unknown category.'),
            'label' => $labelIds[] = $rule->filter_value,
            'domain' => $terms[] = 'from:@'.$rule->filter_value,
            'sender' => $terms[] = 'from:'.$rule->filter_value,
        };

        [$add, $remove] = match ($rule->action) {
            'archive' => [[], ['INBOX']],
            'trash' => [['TRASH'], []],
            'add_label' => [[$rule->target_label], $rule->archive_after_label ? ['INBOX'] : []],
        };

        if ($rule->action === 'archive') {
            $terms[] = 'in:inbox';
        }

        if ($rule->action === 'add_label') {
            if ($targetLabelName === null) {
                throw new LabelMissing('label_missing');
            }

            $terms[] = '-label:"'.str_replace('"', '', $targetLabelName).'"';
        }

        return new self(implode(' ', $terms), $labelIds, $add, $remove);
    }
}
