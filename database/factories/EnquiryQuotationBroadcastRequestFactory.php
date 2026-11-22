<?php

namespace Database\Factories;

use App\Domains\Enquiry\Enums\BroadcastRequestStatus;
use App\Models\Enquiry;
use App\Models\EnquiryQuotationBroadcastRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryQuotationBroadcastRequest> */
class EnquiryQuotationBroadcastRequestFactory extends Factory
{
    protected $model = EnquiryQuotationBroadcastRequest::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'batch_id' => (string) Str::uuid(),
            'channels' => ['whatsapp', 'sms'],
            'status' => BroadcastRequestStatus::Queued,
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => null,
            'actor_user_id' => null,
            'requested_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryQuotationBroadcastRequest $row): void {
            $enquiry = Enquiry::query()->find($row->enquiry_id);
            if ($enquiry !== null) {
                $row->company_id = $enquiry->company_id;
            }
        });
    }
}
