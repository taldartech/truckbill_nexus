<?php

namespace Database\Factories\Crm;

use App\Models\Crm\Lead;
use App\Models\Crm\LeadFollowUp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadFollowUp>
 */
class LeadFollowUpFactory extends Factory
{
    protected $model = LeadFollowUp::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $creator = User::factory()->superAdmin();

        return [
            'lead_id' => Lead::factory(),
            'assigned_to_user_id' => $creator,
            'created_by_user_id' => $creator,
            'due_at' => now()->addDay(),
            'notes' => fake()->sentence(),
            'completed_at' => null,
            'completion_notes' => null,
        ];
    }
}
