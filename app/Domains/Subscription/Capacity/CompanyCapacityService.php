<?php

namespace App\Domains\Subscription\Capacity;

use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscription;
use App\Models\Vehicle;

/**
 * Company capacity evaluation (Rules §15 defaults). Unpaid upgrades are not activated here.
 */
final class CompanyCapacityService
{
    /**
     * Evaluate active capacity including paid extra branches and extra users.
     *
     * @return array{
     *     included_branches: int,
     *     seats_per_included_branch: int,
     *     included_seats: int,
     *     purchased_branches: int,
     *     purchased_seats: int,
     *     purchased_storage_bytes: int,
     *     branch_limit: int,
     *     seat_limit: int,
     *     storage_limit_bytes: int,
     *     owner_is_billable_seat: bool,
     *     used_branches: int,
     *     used_billable_seats: int,
     *     used_vehicles: int,
     *     available_branch_slots: int,
     *     available_seat_slots: int,
     *     available_user_capacity: int,
     *     pending_note: string
     * }
     */
    public function evaluateForCompany(Company $company): array
    {
        [$branches, $seats, $storageBytes] = $this->activePurchasedExtras($company);
        $base = $this->evaluate($company, $branches, $seats);
        $defaultStorage = (int) config('subscription.storage.default_allocation_bytes', 0);

        return array_merge($base, [
            'purchased_storage_bytes' => $storageBytes,
            'storage_limit_bytes' => $defaultStorage + $storageBytes,
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public function activePurchasedExtras(Company $company): array
    {
        $active = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNotNull('activated_at')
            ->whereNull('deleted_at')
            ->with(['items.addon'])
            ->orderByDesc('activated_at')
            ->first();

        if ($active === null) {
            return [0, 0, 0];
        }

        $extraBranches = 0;
        $extraSeats = 0;
        $extraStorage = 0;

        foreach ($active->items as $item) {
            $code = $item->code !== '' ? $item->code : ($item->addon !== null ? $item->addon->code : '');
            $qty = max(1, (int) ($item->quantity ?? 1));
            if ($code === 'extra_branch') {
                $extraBranches += $qty;
            } elseif ($code === 'extra_user') {
                $extraSeats += $qty;
            } elseif ($code === 'extra_storage') {
                $bytesPerUnit = $item->addon !== null ? (int) $item->addon->storage_bytes_per_unit : (5 * 1024 * 1024 * 1024);
                $extraStorage += $qty * max(0, $bytesPerUnit);
            }
        }

        $breakdown = is_array($active->pricing_breakdown) ? $active->pricing_breakdown : [];
        $extraBranches = max($extraBranches, (int) ($breakdown['purchased_branches'] ?? $breakdown['extra_branches'] ?? 0));
        $extraSeats = max($extraSeats, (int) ($breakdown['purchased_seats'] ?? $breakdown['extra_seats'] ?? 0));

        return [$extraBranches, $extraSeats, $extraStorage];
    }

    /**
     * @return array{
     *     included_branches: int,
     *     seats_per_included_branch: int,
     *     included_seats: int,
     *     purchased_branches: int,
     *     purchased_seats: int,
     *     branch_limit: int,
     *     seat_limit: int,
     *     owner_is_billable_seat: bool,
     *     used_branches: int,
     *     used_billable_seats: int,
     *     used_vehicles: int,
     *     available_branch_slots: int,
     *     available_seat_slots: int,
     *     available_user_capacity: int,
     *     pending_note: string
     * }
     */
    public function evaluate(Company $company, int $extraPurchasedBranches = 0, int $extraPurchasedSeats = 0): array
    {
        $includedBranches = (int) config('subscription.capacity.included_branches', 2);
        $seatsPerBranch = (int) config('subscription.capacity.seats_per_included_branch', 3);
        $ownerBillable = (bool) config('subscription.capacity.owner_is_billable_seat', false);
        $purchasedBranches = max(0, $extraPurchasedBranches);
        $purchasedSeats = max(0, $extraPurchasedSeats);

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
        $branchLimit = $includedBranches + $purchasedBranches;
        $seatLimit = $includedSeats + $purchasedSeats;
        $availableSeats = max(0, $seatLimit - $billableSeats);

        return [
            'included_branches' => $includedBranches,
            'seats_per_included_branch' => $seatsPerBranch,
            'included_seats' => $includedSeats,
            'purchased_branches' => $purchasedBranches,
            'purchased_seats' => $purchasedSeats,
            'branch_limit' => $branchLimit,
            'seat_limit' => $seatLimit,
            'owner_is_billable_seat' => $ownerBillable,
            'used_branches' => $usedBranches,
            'used_billable_seats' => $billableSeats,
            'used_vehicles' => $usedVehicles,
            'available_branch_slots' => max(0, $branchLimit - $usedBranches),
            'available_seat_slots' => $availableSeats,
            'available_user_capacity' => $availableSeats,
            'pending_note' => 'Unpaid capacity upgrades must not be treated as active entitlements. Vehicle capacity limits remain catalogue-driven when plan capacity units are present.',
        ];
    }
}
