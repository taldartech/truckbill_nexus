<?php

namespace Database\Factories;

use App\Models\Enquiry;
use App\Models\EnquiryConversion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryConversion> */
class EnquiryConversionFactory extends Factory
{
    protected $model = EnquiryConversion::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory()->converted(),
            'idempotency_key' => null,
            'converted_by_user_id' => null,
            'converted_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryConversion $row): void {
            $enquiry = Enquiry::query()->find($row->enquiry_id);
            if ($enquiry !== null) {
                $row->company_id = $enquiry->company_id;
            }
        });
    }
}
