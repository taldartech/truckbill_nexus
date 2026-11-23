<?php

namespace Database\Factories\Crm;

use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadActivity>
 */
class LeadActivityFactory extends Factory
{
    protected $model = LeadActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'actor_user_id' => User::factory()->superAdmin(),
            'activity_type' => 'note',
            'body' => fake()->sentence(),
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
