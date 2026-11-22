<?php

namespace Database\Factories;

use App\Models\EnquiryQuotationBroadcastRequest;
use App\Models\EnquiryQuotationBroadcastTarget;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryQuotationBroadcastTarget> */
class EnquiryQuotationBroadcastTargetFactory extends Factory
{
    protected $model = EnquiryQuotationBroadcastTarget::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'broadcast_request_id' => EnquiryQuotationBroadcastRequest::factory(),
            'transporter_party_id' => Party::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryQuotationBroadcastTarget $row): void {
            $request = EnquiryQuotationBroadcastRequest::query()->find($row->broadcast_request_id);
            if ($request !== null) {
                $row->company_id = $request->company_id;
                $row->transporter_party_id = Party::factory()->create(['company_id' => $request->company_id])->id;
            }
        });
    }
}
