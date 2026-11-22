<?php

namespace Database\Factories;

use App\Models\EnquiryConversion;
use App\Models\EnquiryConversionLorryReceipt;
use App\Models\LorryReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryConversionLorryReceipt> */
class EnquiryConversionLorryReceiptFactory extends Factory
{
    protected $model = EnquiryConversionLorryReceipt::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_conversion_id' => EnquiryConversion::factory(),
            'lorry_receipt_id' => LorryReceipt::factory(),
            'position' => 1,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryConversionLorryReceipt $row): void {
            if ($row->company_id) {
                return;
            }

            $conversion = EnquiryConversion::query()->find($row->enquiry_conversion_id);
            if ($conversion !== null) {
                $row->company_id = $conversion->company_id;
            }
        });
    }
}
