<?php

namespace Database\Factories;

use App\Models\PaymentMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMode>
 */
class PaymentModeFactory extends Factory
{
    protected $model = PaymentMode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->word(),
            'is_active' => true,
            'sort_order' => 100,
        ];
    }
}
