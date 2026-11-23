<?php

namespace Database\Factories\Crm;

use App\Models\Crm\SupportTicket;
use App\Models\Crm\SupportTicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicketActivity>
 */
class SupportTicketActivityFactory extends Factory
{
    protected $model = SupportTicketActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => SupportTicket::factory(),
            'actor_user_id' => User::factory()->superAdmin(),
            'activity_type' => 'note',
            'body' => fake()->sentence(),
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
