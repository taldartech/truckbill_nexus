<?php

namespace App\Domains\Subscription\Capacity;

use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;

/**
 * Company capacity evaluation (Rules §15 defaults). Unpaid upgrades are not activated here.
 */
final class CompanyCapacityService
{
    /**
     * @return array{
     *     included_branches: int,
     *     seats_per_included_branch: int,
     *     included_seats: int,
     *     owner_is_billable_seat: bool,
     *     used_branches: int,
     *     used_billable_seats: int,
     *     used_vehicles: int,
     *     available_branch_slots: int,
     *     available_seat_slots: int,
     *     pending_note: string
     * }
     */
    public function evaluate(Company $company, int $extraPurchasedBranches = 0, int $extraPurchasedSeats = 0): array
    {
        $includedBranches = (int) config('subscription.capacity.included_branches', 2);
        $seatsPerBranch = (int) config('subscription.capacity.seats_per_included_branch', 3);
        $ownerBillable = (bool) config('subscription.capacity.owner_is_billable_seat', false);

        $usedBranches = Branch::query()->where('company_id', $company->id)->whereNull('deleted_at')->count();
        $memberships = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->get();

        $billableSeats = 0;
        foreach ($memberships as $membership) {
            if (! $ownerBillable && (int) $membership->user_id === (int) $company->owner_user_id) {
                continue;
            }
            $hasBranchSeat = BranchAssignment::query()
                ->where('company_membership_id', $membership->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($hasBranchSeat) {
                $billableSeats++;
            }
        }

        $usedVehicles = Vehicle::query()->where('company_id', $company->id)->whereNull('deleted_at')->count();
        $includedSeats = $includedBranches * $seatsPerBranch;
        $branchLimit = $includedBranches + max(0, $extraPurchasedBranches);
        $seatLimit = $includedSeats + max(0, $extraPurchasedSeats);

        return [
            'included_branches' => $includedBranches,
            'seats_per_included_branch' => $seatsPerBranch,
            'included_seats' => $includedSeats,
            'owner_is_billable_seat' => $ownerBillable,
            'used_branches' => $usedBranches,
            'used_billable_seats' => $billableSeats,
            'used_vehicles' => $usedVehicles,
            'available_branch_slots' => max(0, $branchLimit - $usedBranches),
            'available_seat_slots' => max(0, $seatLimit - $billableSeats),
            'pending_note' => 'Unpaid capacity upgrades must not be treated as active entitlements.',
        ];
    }
}
