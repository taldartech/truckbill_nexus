<?php

namespace Database\Factories;

use App\Models\TdsRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TdsRule>
 */
class TdsRuleFactory extends Factory
{
    protected $model = TdsRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('TDS###')),
            'name' => 'TDS Rule',
            'priority' => 100,
            'is_active' => true,
            'condition_logic' => 'AND',
            'conditions' => [
                ['field' => 'pan_status', 'operator' => 'eq', 'value' => 'active'],
            ],
            'rate_percent' => '1.000',
            'description' => null,
        ];
    }
}
