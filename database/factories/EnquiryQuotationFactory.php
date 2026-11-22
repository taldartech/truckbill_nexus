<?php

namespace Database\Factories;

use App\Domains\Enquiry\Enums\QuotationSelectionStatus;
use App\Models\Enquiry;
use App\Models\EnquiryQuotation;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryQuotation> */
class EnquiryQuotationFactory extends Factory
{
    protected $model = EnquiryQuotation::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'transporter_party_id' => Party::factory(),
            'quoted_freight' => '15000.00',
            'rate_basis' => 'Fixed',
            'vehicle_type_id' => null,
            'vehicle_quantity' => 1,
            'advance_amount' => '1000.00',
            'payment_terms' => 'To pay',
            'expected_delivery_duration' => '3 days',
            'valid_until' => now()->addDays(7),
            'extra_charges_amount' => null,
            'extra_charges_description' => null,
            'remarks' => null,
            'selection_status' => QuotationSelectionStatus::Pending,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryQuotation $quote): void {
            $enquiry = Enquiry::query()->find($quote->enquiry_id);
            if ($enquiry === null) {
                return;
            }
            $quote->company_id = $enquiry->company_id;
            $party = Party::query()->find($quote->transporter_party_id);
            if ($party === null || $party->company_id !== $enquiry->company_id) {
                $quote->transporter_party_id = Party::factory()->create(['company_id' => $enquiry->company_id])->id;
            }
        });
    }
}
