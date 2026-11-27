<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use App\Models\RetentionRule;
use App\Models\RetentionRuleCondition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<RetentionRule>
 */
class RetentionRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'name' => 'Promoções em 15 dias',
            'retention_days' => 15,
            'action' => 'archive',
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $conditions  [type, value] pairs
     */
    public function withConditions(array $conditions): static
    {
        return $this->has(
            RetentionRuleCondition::factory()
                ->count(count($conditions))
                ->state(new Sequence(...array_map(fn (array $c) => ['type' => $c[0], 'value' => $c[1]], $conditions))),
            'conditions',
        );
    }
}
