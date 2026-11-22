<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyMembership>
 */
class CompanyMembershipFactory extends Factory
{
    protected $model = CompanyMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory()->customer(),
            'deactivated_at' => null,
        ];
    }
}
