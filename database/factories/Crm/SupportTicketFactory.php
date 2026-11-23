<?php

namespace Database\Factories\Crm;

use App\Domains\Crm\Enums\SupportTicketPriority;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Models\Crm\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category' => 'system',
            'priority' => SupportTicketPriority::Medium,
            'status' => SupportTicketStatus::Open,
            'requester_user_id' => null,
            'assigned_to_user_id' => null,
            'created_by_user_id' => User::factory()->superAdmin(),
            'related_company_id' => null,
            'due_at' => now()->addDays(2),
        ];
    }
}
