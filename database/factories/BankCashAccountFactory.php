<?php

namespace Database\Factories;

use App\Models\BankCashAccount;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankCashAccount>
 */
class BankCashAccountFactory extends Factory
{
    protected $model = BankCashAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'account_kind' => 'bank',
            'name' => fake()->unique()->company().' Account',
            'bank_name' => 'HDFC Bank',
            'account_number' => fake()->numerify('##########'),
            'ifsc' => 'HDFC0001234',
            'account_holder_name' => fake()->name(),
            'is_active' => true,
        ];
    }
}
