<?php

namespace Database\Factories;

use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Models\Enquiry;
use App\Models\EnquiryStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryStatusHistory> */
class EnquiryStatusHistoryFactory extends Factory
{
    protected $model = EnquiryStatusHistory::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'from_status' => null,
            'to_status' => EnquiryStatus::New,
            'actor_user_id' => null,
            'remark' => null,
            'changed_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryStatusHistory $row): void {
            $enquiry = Enquiry::query()->find($row->enquiry_id);
            if ($enquiry !== null) {
                $row->company_id = $enquiry->company_id;
            }
        });
    }
}
