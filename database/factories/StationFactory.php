<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
class StationFactory extends Factory
{
    protected $model = Station::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory()->customer(),
            'name' => fake()->city().' Station',
            'state' => 'Maharashtra',
            'city' => fake()->city(),
            'is_active' => true,
        ];
    }
}
