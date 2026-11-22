<?php

namespace Database\Factories;

use App\Models\LorryReceipt;
use App\Models\LrEwayBill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LrEwayBill> */
class LrEwayBillFactory extends Factory
{
    protected $model = LrEwayBill::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'lorry_receipt_id' => LorryReceipt::factory(),
            'ewb_number' => fake()->numerify('##############'),
            'ewb_date' => now()->toDateString(),
            'valid_until' => now()->addDays(7)->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (LrEwayBill $row): void {
            $lr = LorryReceipt::query()->find($row->lorry_receipt_id);
            if ($lr !== null) {
                $row->company_id = $lr->company_id;
            }
        });
    }
}
