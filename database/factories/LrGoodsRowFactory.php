<?php

namespace Database\Factories;

use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Models\LorryReceipt;
use App\Models\LrGoodsRow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LrGoodsRow> */
class LrGoodsRowFactory extends Factory
{
    protected $model = LrGoodsRow::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'lorry_receipt_id' => LorryReceipt::factory(),
            'position' => 1,
            'goods_type_id' => null,
            'package_type_id' => null,
            'goods_description' => 'Goods',
            'package_count' => '5',
            'actual_weight' => '10.000',
            'charged_weight' => '10.000',
            'freight_rate_type' => FreightRateType::FixedAmount,
            // Factories create new-style rows unless a test stamps historical weight_kg.
            'rate_semantics' => FreightRateSemantics::WeightMt,
            'freight_rate' => '1000.0000',
            'freight_amount' => '1000.00',
            'goods_value' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LrGoodsRow $row): void {
            $lr = LorryReceipt::query()->find($row->lorry_receipt_id);
            if ($lr !== null) {
                $row->company_id = $lr->company_id;
            }
        });
    }
}
