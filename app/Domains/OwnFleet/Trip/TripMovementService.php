<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Station;
use App\Models\Trip;
use App\Models\TripDrop;
use App\Models\TripDropPickupAllocation;
use App\Models\TripPickup;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet multi-point pickup / drop / allocation engine (Rules §9).
 */
final class TripMovementService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    /**
     * @param  array{
     *     station_id: string,
     *     consignor_party_id?: string|null,
     *     goods_description?: string|null,
     *     weight: string|float|int,
     *     pickup_date: string
     * }  $payload
     */
    public function addPickup(Trip $trip, User $actor, array $payload): TripPickup
    {
        $this->assertEditable($trip, $actor);

        $stationId = (string) ($payload['station_id'] ?? '');
        $weight = $this->requirePositiveDecimal($payload['weight'] ?? null, 'weight', 3);
        $pickupDate = (string) ($payload['pickup_date'] ?? '');
        if ($pickupDate === '') {
            throw ValidationException::withMessages(['pickup_date' => 'Pickup date is required.']);
        }

        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);
        $this->assertStation($stationId);
        $consignorId = filled($payload['consignor_party_id'] ?? null) ? (string) $payload['consignor_party_id'] : null;
        if ($consignorId !== null) {
            $this->assertPartyRole($company, $branch, $consignorId, PartyRoleType::Consignor, 'consignor_party_id');
        }

        return $this->auditedTransaction->run(function () use ($trip, $company, $branch, $stationId, $consignorId, $payload, $weight, $pickupDate): TripPickup {
            return $this->withTenant($company, $branch, function () use ($trip, $stationId, $consignorId, $payload, $weight, $pickupDate): TripPickup {
                $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
                $this->assertTripMutable($locked);

                $position = (int) TripPickup::query()
                    ->where('trip_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->max('position') + 1;

                $pickup = TripPickup::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $locked->company_id,
                    'trip_id' => $locked->id,
                    'position' => $position,
                    'station_id' => $stationId,
                    'consignor_party_id' => $consignorId,
                    'goods_description' => filled($payload['goods_description'] ?? null)
                        ? trim((string) $payload['goods_description'])
                        : null,
                    'weight' => $weight,
                    'pickup_date' => $pickupDate,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    TripPickup::class,
                    $pickup->id,
                    remark: 'Add trip pickup',
                    before: null,
                    after: $pickup->toArray(),
                );

                return $pickup;
            });
        });
    }

    /**
     * @param  array{
     *     station_id: string,
     *     consignee_party_id?: string|null
     * }  $payload
     */
    public function addDrop(Trip $trip, User $actor, array $payload): TripDrop
    {
        $this->assertEditable($trip, $actor);

        $stationId = (string) ($payload['station_id'] ?? '');
        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);
        $this->assertStation($stationId);
        $consigneeId = filled($payload['consignee_party_id'] ?? null) ? (string) $payload['consignee_party_id'] : null;
        if ($consigneeId !== null) {
            $this->assertPartyRole($company, $branch, $consigneeId, PartyRoleType::Consignee, 'consignee_party_id');
        }

        return $this->auditedTransaction->run(function () use ($trip, $company, $branch, $stationId, $consigneeId): TripDrop {
            return $this->withTenant($company, $branch, function () use ($trip, $stationId, $consigneeId): TripDrop {
                $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
                $this->assertTripMutable($locked);

                $position = (int) TripDrop::query()
                    ->where('trip_id', $locked->id)
                    ->whereNull('deleted_at')
                    ->max('position') + 1;

                $drop = TripDrop::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $locked->company_id,
                    'trip_id' => $locked->id,
                    'position' => $position,
                    'station_id' => $stationId,
                    'consignee_party_id' => $consigneeId,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    TripDrop::class,
                    $drop->id,
                    remark: 'Add trip drop',
                    before: null,
                    after: $drop->toArray(),
                );

                return $drop;
            });
        });
    }

    /**
     * @param  array{
     *     trip_pickup_id: string,
     *     trip_drop_id: string,
     *     allocation_weight: string|float|int,
     *     rate?: string|float|int|null,
     *     freight?: string|float|int|null,
     *     distance?: string|float|int|null,
     *     package_count?: string|float|int|null,
     *     ewb_number?: string|null,
     *     goods_value?: string|float|int|null
     * }  $payload
     */
    public function upsertAllocation(Trip $trip, User $actor, array $payload): TripDropPickupAllocation
    {
        $this->assertEditable($trip, $actor);

        $pickupId = (string) ($payload['trip_pickup_id'] ?? '');
        $dropId = (string) ($payload['trip_drop_id'] ?? '');
        $weight = $this->requirePositiveDecimal($payload['allocation_weight'] ?? null, 'allocation_weight', 3);
        $rate = $this->optionalNonNegativeDecimal($payload['rate'] ?? null, 'rate', 4) ?? '0.0000';
        $freight = $this->optionalNonNegativeDecimal($payload['freight'] ?? null, 'freight', 2) ?? '0.00';
        $distance = $this->optionalNonNegativeDecimal($payload['distance'] ?? null, 'distance', 1);
        $packageCount = $this->optionalNonNegativeDecimal($payload['package_count'] ?? null, 'package_count', 3);
        $goodsValue = $this->optionalNonNegativeDecimal($payload['goods_value'] ?? null, 'goods_value', 2);
        $ewb = filled($payload['ewb_number'] ?? null) ? trim((string) $payload['ewb_number']) : null;

        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);

        try {
            return $this->auditedTransaction->run(function () use (
                $trip,
                $company,
                $branch,
                $pickupId,
                $dropId,
                $weight,
                $rate,
                $freight,
                $distance,
                $packageCount,
                $goodsValue,
                $ewb,
            ): TripDropPickupAllocation {
                return $this->withTenant($company, $branch, function () use (
                    $trip,
                    $pickupId,
                    $dropId,
                    $weight,
                    $rate,
                    $freight,
                    $distance,
                    $packageCount,
                    $goodsValue,
                    $ewb,
                ): TripDropPickupAllocation {
                    $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
                    $this->assertTripMutable($locked);

                    $pickup = TripPickup::query()
                        ->where('company_id', $locked->company_id)
                        ->where('trip_id', $locked->id)
                        ->whereKey($pickupId)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    $drop = TripDrop::query()
                        ->where('company_id', $locked->company_id)
                        ->where('trip_id', $locked->id)
                        ->whereKey($dropId)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if ($pickup === null) {
                        throw ValidationException::withMessages(['trip_pickup_id' => 'Pickup not found on this Trip.']);
                    }
                    if ($drop === null) {
                        throw ValidationException::withMessages(['trip_drop_id' => 'Drop not found on this Trip.']);
                    }

                    $existing = TripDropPickupAllocation::query()
                        ->where('trip_drop_id', $drop->id)
                        ->where('trip_pickup_id', $pickup->id)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    $otherRows = TripDropPickupAllocation::query()
                        ->where('trip_pickup_id', $pickup->id)
                        ->whereNull('deleted_at')
                        ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
                        ->lockForUpdate()
                        ->get(['allocation_weight']);

                    $otherAllocated = '0.000';
                    foreach ($otherRows as $otherRow) {
                        $otherAllocated = bcadd($otherAllocated, (string) $otherRow->allocation_weight, 3);
                    }

                    $remaining = bcsub((string) $pickup->weight, $otherAllocated, 3);
                    if (bccomp($weight, $remaining, 3) === 1) {
                        throw ValidationException::withMessages([
                            'allocation_weight' => 'Allocation exceeds remaining pickup weight ('.$remaining.').',
                        ]);
                    }

                    if ($existing !== null) {
                        $before = $existing->toArray();
                        $existing->fill([
                            'allocation_weight' => $weight,
                            'rate' => $rate,
                            'freight' => $freight,
                            'distance' => $distance,
                            'package_count' => $packageCount,
                            'ewb_number' => $ewb,
                            'goods_value' => $goodsValue,
                            'lock_version' => ((int) $existing->lock_version) + 1,
                        ]);
                        $existing->save();

                        $this->audit->write(
                            AuditAction::Updated,
                            TripDropPickupAllocation::class,
                            $existing->id,
                            remark: 'Update trip allocation',
                            before: $before,
                            after: $existing->fresh()?->toArray(),
                        );

                        return $existing->fresh() ?? $existing;
                    }

                    $allocation = TripDropPickupAllocation::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $locked->company_id,
                        'trip_id' => $locked->id,
                        'trip_drop_id' => $drop->id,
                        'trip_pickup_id' => $pickup->id,
                        'allocation_weight' => $weight,
                        'rate' => $rate,
                        'freight' => $freight,
                        'distance' => $distance,
                        'package_count' => $packageCount,
                        'ewb_number' => $ewb,
                        'goods_value' => $goodsValue,
                        'lock_version' => 0,
                    ]);

                    $this->audit->write(
                        AuditAction::Created,
                        TripDropPickupAllocation::class,
                        $allocation->id,
                        remark: 'Create trip allocation',
                        before: null,
                        after: $allocation->toArray(),
                    );

                    return $allocation;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new TripConflictException('Allocation already exists for this pickup and drop.', previous: $e);
        }
    }

    public function removeAllocation(Trip $trip, User $actor, TripDropPickupAllocation $allocation): void
    {
        $this->assertEditable($trip, $actor);

        if ($allocation->trip_id !== $trip->id || $allocation->company_id !== $trip->company_id) {
            throw new TripDomainException('Allocation does not belong to this Trip.');
        }

        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);

        $this->auditedTransaction->run(function () use ($trip, $company, $branch, $allocation): void {
            $this->withTenant($company, $branch, function () use ($trip, $allocation): void {
                $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
                $this->assertTripMutable($locked);

                $row = TripDropPickupAllocation::query()
                    ->whereKey($allocation->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    throw new TripDomainException('Allocation not found.');
                }

                $before = $row->toArray();
                $row->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    TripDropPickupAllocation::class,
                    $row->id,
                    remark: 'Remove trip allocation',
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return array{
     *     pickups: list<array{id: string, weight: string, allocated: string, remaining: string, reconciled: bool}>,
     *     total_freight: string,
     *     all_reconciled: bool,
     *     freight_positive: bool,
     *     completion_ready: bool
     * }
     */
    public function reconciliationSummary(Trip $trip): array
    {
        $pickups = TripPickup::query()
            ->where('trip_id', $trip->id)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get();

        $rows = [];
        $allReconciled = $pickups->isNotEmpty();
        $totalFreight = '0.00';

        foreach ($pickups as $pickup) {
            $allocated = TripDropPickupAllocation::query()
                ->where('trip_pickup_id', $pickup->id)
                ->whereNull('deleted_at')
                ->sum('allocation_weight');
            $allocatedStr = $this->numericString((string) $allocated, 3);
            $remaining = bcsub((string) $pickup->weight, $allocatedStr, 3);
            $reconciled = bccomp($remaining, '0.000', 3) === 0;
            if (! $reconciled) {
                $allReconciled = false;
            }
            $rows[] = [
                'id' => $pickup->id,
                'weight' => (string) $pickup->weight,
                'allocated' => $allocatedStr,
                'remaining' => $remaining,
                'reconciled' => $reconciled,
            ];
        }

        if ($pickups->isEmpty()) {
            $allReconciled = false;
        }

        $totalFreight = $this->numericString((string) TripDropPickupAllocation::query()
            ->where('trip_id', $trip->id)
            ->whereNull('deleted_at')
            ->sum('freight'), 2);

        $freightPositive = bccomp($totalFreight, '0.00', 2) === 1;

        return [
            'pickups' => $rows,
            'total_freight' => $totalFreight,
            'all_reconciled' => $allReconciled,
            'freight_positive' => $freightPositive,
            'completion_ready' => $allReconciled && $freightPositive,
        ];
    }

    public function assertCompletionReconciliation(Trip $trip): void
    {
        $summary = $this->reconciliationSummary($trip);
        if (! $summary['all_reconciled']) {
            throw ValidationException::withMessages([
                'allocations' => 'Every pickup weight must be fully allocated before completion.',
            ]);
        }
        if (! $summary['freight_positive']) {
            throw ValidationException::withMessages([
                'allocations' => 'Total allocation freight must be greater than zero before completion.',
            ]);
        }
    }

    private function assertEditable(Trip $trip, User $actor): void
    {
        $company = Company::query()->findOrFail($trip->company_id);
        $branch = Branch::query()->findOrFail($trip->branch_id);

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_EDIT, $company, $branch)) {
            throw new TripAuthorizationException('Not authorized to edit OwnFleet Trip movements.');
        }

        $this->assertTripMutable($trip);
    }

    private function assertTripMutable(Trip $trip): void
    {
        if ($trip->trashed()) {
            throw new TripDomainException('Cannot modify a deleted Trip.');
        }

        if (! in_array($trip->status, [TripStatus::Pending, TripStatus::InProgress], true)) {
            throw new TripDomainException('Pickups and allocations can only change on Pending or In Progress Trips.');
        }
    }

    private function assertStation(string $stationId): void
    {
        $exists = Station::query()->whereKey($stationId)->whereNull('deleted_at')->where('is_active', true)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['station_id' => 'Station is not available.']);
        }
    }

    private function assertPartyRole(
        Company $company,
        Branch $branch,
        string $partyId,
        PartyRoleType $role,
        string $field,
    ): void {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([$field => 'Party was not found.']);
        }

        if ($party->branch_id !== null && $party->branch_id !== $branch->id) {
            throw ValidationException::withMessages([$field => 'Party is not visible in this branch.']);
        }

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', $role->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([$field => 'Party must have an active '.$role->value.' role.']);
        }
    }

    private function requirePositiveDecimal(mixed $value, string $field, int $scale): string
    {
        $normalized = $this->requireDecimal($value, $field, $scale);
        if (bccomp($normalized, $this->zero($scale), $scale) !== 1) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' must be greater than zero.']);
        }

        return $normalized;
    }

    private function optionalNonNegativeDecimal(mixed $value, string $field, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = $this->requireDecimal($value, $field, $scale);
        if (bccomp($normalized, $this->zero($scale), $scale) === -1) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' cannot be negative.']);
        }

        return $normalized;
    }

    private function requireDecimal(mixed $value, string $field, int $scale): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([$field => 'A valid number is required.']);
        }

        return $this->numericString((string) $value, $scale);
    }

    private function numericString(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }

    private function zero(int $scale): string
    {
        return bcadd('0', '0', $scale);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }
}
