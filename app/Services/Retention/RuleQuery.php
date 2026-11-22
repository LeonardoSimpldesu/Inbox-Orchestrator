<?php

namespace App\Services\Retention;

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
     * @param  list<string>  $excludeLabelIds
     */
    private function __construct(
        public string $query,
        public array $labelIds,
        public array $add,
        public array $remove,
        public ?string $excludeQuery = null,
        public array $excludeLabelIds = [],
    ) {}

    public static function for(RetentionRule $rule): self
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

        $query = implode(' ', $terms);

        // Mail that already has the target label is left out by subtracting ids (listed
        // with the label's id), so label names never have to be written in a search.
        // With "also archive", labeled mail still in the inbox stays in.
        if ($rule->action === 'add_label') {
            return new self(
                $query, $labelIds, $add, $remove,
                excludeQuery: $rule->archive_after_label ? $query.' -in:inbox' : $query,
                excludeLabelIds: [...$labelIds, $rule->target_label],
            );
        }

        return new self($query, $labelIds, $add, $remove);
    }
}
