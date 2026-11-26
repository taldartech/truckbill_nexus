<?php

namespace App\Domains\OwnFleet\Driver;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet Primary Driver Assignment service.
 * Reuses Party Master (PartyRoleType::Driver) and keeps vehicles.primary_driver_party_id
 * synchronized without creating duplicate Driver identities or altering historical Trips.
 */
final class VehicleDriverAssignmentService
{
    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * Register rows for branch-visible Own Vehicles with active/scheduled assignment and driver metadata.
     *
     * @return list<array<string, mixed>>
     */
    public function registerRows(
        Company $company,
        Branch $branch,
        ?string $query = null,
        ?string $status = null,
        ?string $asOfDate = null,
    ): array {
        $asOf = $this->normalizeDate($company, $asOfDate);

        $vehicles = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->where('ownership', VehicleOwnership::Own->value)
            ->whereNull('deleted_at')
            ->with([
                'truckType:id,name',
                'primaryDriver.roles' => fn ($roleQuery) => $roleQuery
                    ->where('role', PartyRoleType::Driver->value)
                    ->whereNull('deleted_at'),
                'driverAssignments' => fn ($assignQuery) => $assignQuery
                    ->where('is_primary', true)
                    ->whereNull('deleted_at')
                    ->with([
                        'driver.roles' => fn ($roleQuery) => $roleQuery
                            ->where('role', PartyRoleType::Driver->value)
                            ->whereNull('deleted_at'),
                    ])
                    ->orderByDesc('assigned_from')
                    ->orderByDesc('created_at'),
            ])
            ->orderBy('registration_number')
            ->get();

        $rows = [];
        $q = $query !== null ? mb_strtolower(trim($query)) : '';

        foreach ($vehicles as $vehicle) {
            /** @var VehicleDriverAssignment|null $activeAssignment */
            $activeAssignment = $vehicle->driverAssignments->first(
                fn (VehicleDriverAssignment $row): bool => $row->statusOn($asOf) === 'active',
            );
            /** @var VehicleDriverAssignment|null $scheduledAssignment */
            $scheduledAssignment = $vehicle->driverAssignments->first(
                fn (VehicleDriverAssignment $row): bool => $row->statusOn($asOf) === 'scheduled',
            );

            $driver = ($activeAssignment !== null && $activeAssignment->driver !== null)
                ? $activeAssignment->driver
                : $vehicle->primaryDriver;
            $driverRole = $driver?->roles
                ->first(fn (PartyRole $role): bool => $role->role === PartyRoleType::Driver->value && $role->deactivated_at === null);
            $attrs = is_array($driverRole?->role_attributes) ? $driverRole->role_attributes : [];

            $licenseNumber = isset($attrs['license_number']) ? (string) $attrs['license_number'] : null;
            $licenseExpiry = isset($attrs['license_expiry']) ? (string) $attrs['license_expiry'] : null;
            $assignmentStatus = $activeAssignment !== null || $vehicle->primary_driver_party_id !== null
                ? 'assigned'
                : ($scheduledAssignment !== null ? 'scheduled' : 'unassigned');

            if ($status !== null && $status !== '' && $assignmentStatus !== $status) {
                continue;
            }

            if ($q !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $vehicle->registration_number,
                    $driver?->name,
                    $driver?->contact_number,
                    $licenseNumber,
                ])));
                if (! str_contains($haystack, $q)) {
                    continue;
                }
            }

            $rows[] = [
                'vehicle_id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'vehicle_is_active' => (bool) $vehicle->is_active,
                'truck_type_name' => $vehicle->truckType?->name,
                'assignment_id' => $activeAssignment?->id,
                'driver_party_id' => $driver?->id,
                'driver_name' => $driver?->name,
                'driver_contact_number' => $driver?->contact_number,
                'driver_license_number' => $licenseNumber,
                'driver_license_expiry' => $licenseExpiry,
                'license_expired' => $licenseExpiry !== null && $licenseExpiry < $asOf,
                'assignment_date' => $activeAssignment?->assigned_from->toDateString()
                    ?? $scheduledAssignment?->assigned_from->toDateString(),
                'assigned_to' => $activeAssignment?->assigned_to?->toDateString(),
                'scheduled_driver_name' => $scheduledAssignment?->driver?->name,
                'scheduled_from' => $scheduledAssignment?->assigned_from->toDateString(),
                'assignment_status' => $assignmentStatus,
                'history_count' => $vehicle->driverAssignments->count(),
            ];
        }

        return $rows;
    }

    /**
     * Assignment history for one Own Vehicle (most recent first).
     *
     * @return Collection<int, VehicleDriverAssignment>
     */
    public function historyForVehicle(Company $company, Vehicle $vehicle): Collection
    {
        if ($vehicle->company_id !== $company->id) {
            return collect();
        }

        return VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('deleted_at')
            ->with([
                'driver.roles' => fn ($roleQuery) => $roleQuery
                    ->where('role', PartyRoleType::Driver->value)
                    ->whereNull('deleted_at'),
            ])
            ->orderByDesc('assigned_from')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Current active primary driver assignment for a vehicle on a date (defaults to company today).
     */
    public function currentPrimaryForVehicle(
        Company $company,
        Vehicle $vehicle,
        ?string $asOfDate = null,
    ): ?VehicleDriverAssignment {
        $asOf = $this->normalizeDate($company, $asOfDate);

        return VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $vehicle->id)
            ->activePrimaryOn($asOf)
            ->with(['driver'])
            ->orderByDesc('assigned_from')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Assign or replace the Primary Driver on an Own Vehicle.
     */
    public function assignPrimaryDriver(
        User $actor,
        Company $company,
        ?Branch $branch,
        Vehicle $vehicle,
        string $driverPartyId,
        string $assignedFrom,
        ?string $assignedTo = null,
        ?string $remarks = null,
        bool $syncVehicle = true,
    ): VehicleDriverAssignment {
        $this->assertCanMutate($actor, $company, $branch);
        $this->assertOwnVehicle($company, $vehicle);

        $fromDate = CarbonImmutable::parse($assignedFrom)->toDateString();
        $toDate = filled($assignedTo) ? CarbonImmutable::parse((string) $assignedTo)->toDateString() : null;

        if ($toDate !== null && $toDate < $fromDate) {
            throw ValidationException::withMessages([
                'assigned_to' => 'Effective To date must be on or after Effective Assignment Date.',
            ]);
        }

        [$driver, $driverRole] = $this->assertActiveDriverParty($company, $branch, $driverPartyId, 'driver_party_id');
        $this->assertAssignmentNotBeforeJoiningDate($driverRole, $fromDate);
        $this->assertWithinDriverAvailabilityLimits($company, $driver->id, $vehicle->id, $fromDate, $toDate);
        $this->assertDriverNotPrimaryOnOtherVehicle($company, $driver->id, $vehicle->id, $fromDate, $toDate);

        $today = $this->normalizeDate($company, null);

        return $this->auditedTransaction->run(function () use (
            $actor,
            $company,
            $branch,
            $vehicle,
            $driver,
            $fromDate,
            $toDate,
            $remarks,
            $syncVehicle,
            $today,
        ): VehicleDriverAssignment {
            return $this->access->withTenant($actor, $company, $branch, function () use (
                $actor,
                $company,
                $branch,
                $vehicle,
                $driver,
                $fromDate,
                $toDate,
                $remarks,
                $syncVehicle,
                $today,
            ): VehicleDriverAssignment {
                $this->closeVehiclePrimaryAssignmentsBeforeNew($company, $vehicle->id, $fromDate, $actor);

                $assignment = VehicleDriverAssignment::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch instanceof Branch ? $branch->id : $vehicle->branch_id,
                    'vehicle_id' => $vehicle->id,
                    'driver_party_id' => $driver->id,
                    'assigned_from' => $fromDate,
                    'assigned_to' => $toDate,
                    'is_primary' => true,
                    'remarks' => filled($remarks) ? trim((string) $remarks) : null,
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                ]);

                if ($syncVehicle && $assignment->statusOn($today) === 'active') {
                    $vehicle->forceFill(['primary_driver_party_id' => $driver->id])->save();
                }

                $this->audit->write(
                    AuditAction::Created,
                    VehicleDriverAssignment::class,
                    $assignment->id,
                    remark: filled($remarks) ? (string) $remarks : "Assigned primary driver {$driver->name} to {$vehicle->registration_number}",
                    before: null,
                    after: $assignment->toArray(),
                );

                return $assignment->fresh(['driver', 'vehicle']) ?? $assignment;
            });
        });
    }

    /**
     * Remove/end the current Primary Driver from an Own Vehicle while preserving assignment history & Trips.
     */
    public function removePrimaryDriver(
        User $actor,
        Company $company,
        ?Branch $branch,
        Vehicle $vehicle,
        ?string $effectiveDate = null,
        ?string $remarks = null,
    ): ?VehicleDriverAssignment {
        $this->assertCanMutate($actor, $company, $branch);
        $this->assertOwnVehicle($company, $vehicle);

        $asOf = $this->normalizeDate($company, $effectiveDate);
        $active = $this->currentPrimaryForVehicle($company, $vehicle, $asOf);

        if ($active === null && $vehicle->primary_driver_party_id === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'No primary driver is currently assigned to this vehicle.',
            ]);
        }

        return $this->auditedTransaction->run(function () use (
            $actor,
            $company,
            $branch,
            $vehicle,
            $active,
            $asOf,
            $remarks,
        ): ?VehicleDriverAssignment {
            return $this->access->withTenant($actor, $company, $branch, function () use (
                $actor,
                $vehicle,
                $active,
                $asOf,
                $remarks,
            ): ?VehicleDriverAssignment {
                if ($active !== null) {
                    $before = $active->toArray();
                    $endDate = $active->assigned_from->toDateString() > $asOf
                        ? $active->assigned_from->toDateString()
                        : $asOf;

                    $active->forceFill([
                        'assigned_to' => $endDate,
                        'remarks' => filled($remarks) ? trim((string) $remarks) : $active->remarks,
                        'updated_by_user_id' => $actor->id,
                    ])->save();

                    $this->audit->write(
                        AuditAction::Updated,
                        VehicleDriverAssignment::class,
                        $active->id,
                        remark: filled($remarks) ? (string) $remarks : "Removed primary driver from {$vehicle->registration_number}",
                        before: $before,
                        after: $active->toArray(),
                    );
                }

                if ($vehicle->primary_driver_party_id !== null) {
                    $vehicle->forceFill(['primary_driver_party_id' => null])->save();
                }

                return $active;
            });
        });
    }

    /**
     * Keep assignment history synchronized when Vehicle Master creates/edits primary_driver_party_id.
     */
    public function syncFromVehicleMaster(
        User $actor,
        Company $company,
        ?Branch $branch,
        Vehicle $vehicle,
        ?string $previousDriverId,
        ?string $newDriverId,
        string $remark = 'Synced from Vehicle Master',
    ): void {
        if ($vehicle->ownership !== VehicleOwnership::Own) {
            $active = $this->currentPrimaryForVehicle($company, $vehicle);
            if ($active !== null) {
                $today = $this->normalizeDate($company, null);
                $active->forceFill([
                    'assigned_to' => $today,
                    'updated_by_user_id' => $actor->id,
                ])->save();
            }

            return;
        }

        if ($newDriverId === $previousDriverId) {
            if ($newDriverId !== null && $this->currentPrimaryForVehicle($company, $vehicle) === null) {
                $this->assignPrimaryDriver(
                    actor: $actor,
                    company: $company,
                    branch: $branch,
                    vehicle: $vehicle,
                    driverPartyId: $newDriverId,
                    assignedFrom: $this->normalizeDate($company, null),
                    remarks: $remark,
                    syncVehicle: false,
                );
            }

            return;
        }

        $today = $this->normalizeDate($company, null);

        if ($newDriverId === null) {
            $active = $this->currentPrimaryForVehicle($company, $vehicle, $today);
            if ($active !== null) {
                $active->forceFill([
                    'assigned_to' => $active->assigned_from->toDateString() > $today
                        ? $active->assigned_from->toDateString()
                        : $today,
                    'remarks' => $remark,
                    'updated_by_user_id' => $actor->id,
                ])->save();
            }

            return;
        }

        $this->assignPrimaryDriver(
            actor: $actor,
            company: $company,
            branch: $branch,
            vehicle: $vehicle,
            driverPartyId: $newDriverId,
            assignedFrom: $today,
            remarks: $remark,
            syncVehicle: false,
        );
    }

    private function assertCanMutate(User $actor, Company $company, ?Branch $branch): void
    {
        $capabilities = app(CompanyCapabilityResolver::class);
        if (! $capabilities->allowsMutate($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS)) {
            throw new AuthorizationException($capabilities->denyMessage($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS));
        }

        if ($branch !== null) {
            $allowed = app(CompanyAccessService::class)->isCompanyOwner($actor, $company)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch)
                || $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch);

            if (! $allowed) {
                throw new AuthorizationException('You are not authorized to manage vehicle driver assignments.');
            }
        } else {
            $this->access->assertCanMutate($actor, $company, null);
        }
    }

    private function assertOwnVehicle(Company $company, Vehicle $vehicle): void
    {
        if ($vehicle->company_id !== $company->id || $vehicle->trashed()) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle was not found in this company.',
            ]);
        }

        if ($vehicle->ownership !== VehicleOwnership::Own) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Driver assignment is only permitted for OwnFleet Own Vehicles.',
            ]);
        }

        if (! $vehicle->is_active) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Selected Own Vehicle is inactive.',
            ]);
        }
    }

    /**
     * @return array{0: Party, 1: PartyRole}
     */
    private function assertActiveDriverParty(
        Company $company,
        ?Branch $branch,
        string $driverPartyId,
        string $field,
    ): array {
        $builder = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($driverPartyId)
            ->whereNull('deleted_at');

        if ($branch !== null) {
            $builder->visibleInBranch($company->id, $branch->id);
        }

        $driver = $builder->first();
        if ($driver === null) {
            throw ValidationException::withMessages([
                $field => 'Selected Driver was not found or is not visible in this branch.',
            ]);
        }

        $role = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $driver->id)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->first();

        if ($role === null) {
            throw ValidationException::withMessages([
                $field => 'Selected Party must have an active Driver role.',
            ]);
        }

        return [$driver, $role];
    }

    private function assertAssignmentNotBeforeJoiningDate(PartyRole $role, string $assignedFrom): void
    {
        $attrs = is_array($role->role_attributes) ? $role->role_attributes : [];
        $joining = isset($attrs['joining_date']) ? trim((string) $attrs['joining_date']) : '';
        if ($joining === '') {
            return;
        }

        if ($assignedFrom < $joining) {
            throw ValidationException::withMessages([
                'assigned_from' => "Effective Assignment Date cannot be earlier than the driver's joining date ({$joining}).",
            ]);
        }
    }

    private function assertWithinDriverAvailabilityLimits(
        Company $company,
        string $driverPartyId,
        string $vehicleId,
        string $assignedFrom,
        ?string $assignedTo,
    ): void {
        $nextAssignment = VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->where('driver_party_id', $driverPartyId)
            ->where('vehicle_id', '!=', $vehicleId)
            ->where('is_primary', true)
            ->whereNull('deleted_at')
            ->whereDate('assigned_from', '>', $assignedFrom)
            ->orderBy('assigned_from')
            ->first();

        if ($nextAssignment === null) {
            return;
        }

        $availableUntil = $nextAssignment->assigned_from->subDay()->toDateString();

        if ($assignedTo === null) {
            throw ValidationException::withMessages([
                'assigned_to' => "This driver has a subsequent assignment starting {$nextAssignment->assigned_from->toDateString()}. Specify an Effective To date on or before {$availableUntil}.",
            ]);
        }

        if ($assignedTo > $availableUntil) {
            throw ValidationException::withMessages([
                'assigned_to' => "Effective To date cannot be later than {$availableUntil} due to a subsequent assignment.",
            ]);
        }
    }

    private function assertDriverNotPrimaryOnOtherVehicle(
        Company $company,
        string $driverPartyId,
        string $vehicleId,
        string $assignedFrom,
        ?string $assignedTo,
    ): void {
        $conflict = VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->where('driver_party_id', $driverPartyId)
            ->where('vehicle_id', '!=', $vehicleId)
            ->overlappingPrimaryPeriod($assignedFrom, $assignedTo)
            ->with('vehicle:id,registration_number')
            ->first();

        if ($conflict !== null) {
            $reg = $conflict->vehicle !== null ? $conflict->vehicle->registration_number : 'another vehicle';

            throw ValidationException::withMessages([
                'driver_party_id' => "This driver is already assigned as primary on {$reg} for overlapping dates. Remove or end the other assignment first.",
            ]);
        }

        // Also check active vehicles.primary_driver_party_id in case a legacy/seeded vehicle lacks an assignment row.
        $activeVehicleConflict = Vehicle::query()
            ->where('company_id', $company->id)
            ->where('ownership', VehicleOwnership::Own->value)
            ->where('primary_driver_party_id', $driverPartyId)
            ->whereKeyNot($vehicleId)
            ->whereNull('deleted_at')
            ->whereDoesntHave('driverAssignments', function ($query): void {
                $query->where('is_primary', true)->whereNull('deleted_at');
            })
            ->first();

        if ($activeVehicleConflict !== null) {
            throw ValidationException::withMessages([
                'driver_party_id' => "This driver is already assigned as primary on {$activeVehicleConflict->registration_number}.",
            ]);
        }
    }

    private function closeVehiclePrimaryAssignmentsBeforeNew(
        Company $company,
        string $vehicleId,
        string $newAssignedFrom,
        User $actor,
    ): void {
        $newFrom = CarbonImmutable::parse($newAssignedFrom);

        $assignments = VehicleDriverAssignment::query()
            ->where('company_id', $company->id)
            ->where('vehicle_id', $vehicleId)
            ->where('is_primary', true)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($newFrom): void {
                $query->whereNull('assigned_to')
                    ->orWhereDate('assigned_to', '>=', $newFrom->toDateString());
            })
            ->get();

        foreach ($assignments as $assignment) {
            $from = CarbonImmutable::parse($assignment->assigned_from->toDateString());
            $closedTo = $from->lt($newFrom)
                ? $newFrom->subDay()->toDateString()
                : $from->toDateString();

            $assignment->forceFill([
                'assigned_to' => $closedTo,
                'updated_by_user_id' => $actor->id,
            ])->save();
        }
    }

    private function normalizeDate(Company $company, ?string $date): string
    {
        if (filled($date)) {
            return CarbonImmutable::parse((string) $date)->toDateString();
        }

        $tz = filled($company->timezone) ? (string) $company->timezone : 'Asia/Kolkata';

        return CarbonImmutable::now($tz)->toDateString();
    }
}
