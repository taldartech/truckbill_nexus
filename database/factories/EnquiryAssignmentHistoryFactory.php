<?php

namespace Database\Factories;

use App\Models\Enquiry;
use App\Models\EnquiryAssignmentHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryAssignmentHistory> */
class EnquiryAssignmentHistoryFactory extends Factory
{
    protected $model = EnquiryAssignmentHistory::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'from_branch_id' => null,
            'to_branch_id' => null,
            'from_assigned_user_id' => null,
            'to_assigned_user_id' => null,
            'actor_user_id' => User::factory()->customer(),
            'remark' => 'Assigned',
            'assigned_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryAssignmentHistory $row): void {
            $enquiry = Enquiry::query()->find($row->enquiry_id);
            if ($enquiry !== null) {
                $row->company_id = $enquiry->company_id;
            }
        });
    }
}
