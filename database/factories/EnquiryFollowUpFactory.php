<?php

namespace Database\Factories;

use App\Models\Enquiry;
use App\Models\EnquiryFollowUp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryFollowUp> */
class EnquiryFollowUpFactory extends Factory
{
    protected $model = EnquiryFollowUp::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'follow_up_at' => now()->addDay(),
            'remarks' => 'Call customer',
            'actor_user_id' => User::factory()->customer(),
            'completed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryFollowUp $row): void {
            $enquiry = Enquiry::query()->find($row->enquiry_id);
            if ($enquiry !== null) {
                $row->company_id = $enquiry->company_id;
            }
        });
    }
}
