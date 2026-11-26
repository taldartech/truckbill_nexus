<?php

namespace Database\Factories;

use App\Models\OwnerTyreLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerTyreLayout>
 */
class OwnerTyreLayoutFactory extends Factory
{
    protected $model = OwnerTyreLayout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory()->customer(),
            'name' => fake()->unique()->words(3, true),
            'spare_tyre_count' => 1,
            'drive_tyre_count' => 0,
            'needs_axle_completion' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
