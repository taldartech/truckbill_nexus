<?php

namespace Database\Factories;

use App\Models\LorryReceipt;
use App\Models\LrAdditionalCharge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LrAdditionalCharge> */
class LrAdditionalChargeFactory extends Factory
{
    protected $model = LrAdditionalCharge::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'lorry_receipt_id' => LorryReceipt::factory(),
            'description' => 'Loading',
            'amount' => '500.00',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LrAdditionalCharge $row): void {
            $lr = LorryReceipt::query()->find($row->lorry_receipt_id);
            if ($lr !== null) {
                $row->company_id = $lr->company_id;
            }
        });
    }
}
