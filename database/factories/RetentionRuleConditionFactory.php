<?php

namespace Database\Factories;

use App\Models\RetentionRuleCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionRuleCondition>
 */
class RetentionRuleConditionFactory extends Factory
{
    public function definition(): array
    {
        return ['type' => 'category', 'value' => 'promotions'];
    }
}
