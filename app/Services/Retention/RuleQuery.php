<?php

namespace App\Services\Retention;

use App\Models\RetentionRule;
use App\Services\Providers\GmailSearch;
use InvalidArgumentException;

/**
 * Translates a rule into a Gmail search plus the label changes to apply.
 * Simulation and execution share it, so the simulated count is what runs.
 */
final readonly class RuleQuery
{
    /** Mail a rule must never touch, whatever its filter. */
    private const ALWAYS_EXCLUDED = GmailSearch::PROTECTED;

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

        if ($rule->conditions->isEmpty()) {
            // Without conditions the search would match the whole mailbox.
            throw new InvalidArgumentException('Rule has no conditions.');
        }

        $byType = $rule->conditions->groupBy('type')->map->pluck('value');

        foreach (RetentionRule::CONDITION_TYPES as $type) {
            $values = $byType->get($type)?->all();

            if ($values === null) {
                continue;
            }

            if ($type === 'label' || ($type === 'category' && count($values) === 1)) {
                array_push($labelIds, ...array_map(fn (string $value) => self::labelId($type, $value), $values));

                continue;
            }

            $terms[] = match ($type) {
                // Several categories are OR'ed in the search: labelIds would AND them.
                'category' => self::group(array_map(fn (string $value) => 'category:'.self::categoryKey($value), $values)),
                'domain' => self::group(array_map(fn (string $value) => 'from:@'.$value, $values)),
                'sender' => self::group(array_map(fn (string $value) => GmailSearch::from($value), $values)),
                'subject' => count($values) === 1
                    ? 'subject:"'.$values[0].'"'
                    : 'subject:('.implode(' OR ', array_map(fn (string $value) => '"'.$value.'"', $values)).')',
            };
        }

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

    private static function labelId(string $type, string $value): string
    {
        return $type === 'label' ? $value : RetentionRule::CATEGORIES[self::categoryKey($value)];
    }

    private static function categoryKey(string $value): string
    {
        return array_key_exists($value, RetentionRule::CATEGORIES) ? $value : throw new InvalidArgumentException('Unknown category.');
    }

    /**
     * @param  list<string>  $terms
     */
    private static function group(array $terms): string
    {
        return count($terms) === 1 ? $terms[0] : '{'.implode(' ', $terms).'}';
    }
}
