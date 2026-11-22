<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\CompanyMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchAssignment>
 */
class BranchAssignmentFactory extends Factory
{
    protected $model = BranchAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $membership = CompanyMembership::factory()->create();
        $branch = Branch::factory()->create([
            'company_id' => $membership->company_id,
        ]);

        return [
            'company_id' => $membership->company_id,
            'company_membership_id' => $membership->id,
            'branch_id' => $branch->id,
            'deactivated_at' => null,
        ];
    }
}
