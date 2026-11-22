<?php

namespace Database\Factories;

use App\Models\Enquiry;
use App\Models\EnquiryGoodsRow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EnquiryGoodsRow> */
class EnquiryGoodsRowFactory extends Factory
{
    protected $model = EnquiryGoodsRow::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'enquiry_id' => Enquiry::factory(),
            'position' => 1,
            'goods_type_id' => null,
            'package_type_id' => null,
            'goods_description' => fake()->words(3, true),
            'package_count' => '10.000',
            'actual_weight' => '12.500',
            'charged_weight' => '13.000',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EnquiryGoodsRow $row): void {
            if ($row->enquiry_id && ! $row->company_id) {
                $enquiry = Enquiry::query()->find($row->enquiry_id);
                if ($enquiry !== null) {
                    $row->company_id = $enquiry->company_id;
                }
            }
        });
    }
}
