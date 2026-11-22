<?php

namespace Database\Factories;

use App\Models\Enquiry;
use App\Models\EnquiryQuotation;
use App\Models\EnquiryQuotationSelectionHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryQuotationSelectionHistory> */
class EnquiryQuotationSelectionHistoryFactory extends Factory
{
    protected $model = EnquiryQuotationSelectionHistory::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'quotation_id' => EnquiryQuotation::factory(),
            'action' => 'preferred',
            'actor_user_id' => User::factory()->customer(),
            'remark' => null,
            'selected_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryQuotationSelectionHistory $row): void {
            $enquiry = Enquiry::query()->find($row->enquiry_id);
            if ($enquiry !== null) {
                $row->company_id = $enquiry->company_id;
            }
        });
    }
}
