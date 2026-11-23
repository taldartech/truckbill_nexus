<?php

namespace App\Domains\OwnFleet\Trip;

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripAuthorizationException;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OwnFleet Trip orchestration — 1 OwnFleet LR = 1 Trip (Rules §9 / PRD §11).
 */
final class TripService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Trip>
     */
    public function search(
        Company $company,
        Branch $branch,
        ?string $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->ensureTenantMatches($company, $branch);

        $builder = Trip::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['lorryReceipt.fromStation', 'lorryReceipt.toStation', 'vehicle', 'driver'])
            ->orderByDesc('created_at');

        if (filled($status)) {
            $builder->where('status', $status);
        }

        return $builder->paginate($perPage);
    }

    /**
     * @param  array{
     *     vehicle_id: string,
     *     use_substitute_driver?: bool,
     *     substitute_driver_party_id?: string|null,
     *     expected_end_at?: string|null
     * }  $payload
     */
    public function createFromLorryReceipt(
        LorryReceipt $lorryReceipt,
        User $actor,
        array $payload,
    ): Trip {
        $company = Company::query()->findOrFail($lorryReceipt->company_id);
        $branch = Branch::query()->findOrFail($lorryReceipt->branch_id);

        $this->assertCanCreate($actor, $company, $branch);
        $this->assertOwnFleetEntitled($company);
        $this->assertLorryReceiptEligible($lorryReceipt);

        $vehicleId = (string) ($payload['vehicle_id'] ?? '');
        if ($vehicleId === '') {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is required.',
            ]);
        }

        $vehicle = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicleId)
            ->whereNull('deleted_at')
            ->first();

        if ($vehicle === null || ! $vehicle->is_active) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is not available in this company.',
            ]);
        }

        $vehicleVisible = Vehicle::query()
            ->whereKey($vehicle->id)
            ->visibleInBranch($company->id, $branch->id)
            ->exists();

        if (! $vehicleVisible) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Vehicle is not visible in this branch.',
            ]);
        }

        $useSubstitute = (bool) ($payload['use_substitute_driver'] ?? false);
        $primaryDriverId = $vehicle->primary_driver_party_id;
        $substituteId = filled($payload['substitute_driver_party_id'] ?? null)
            ? (string) $payload['substitute_driver_party_id']
            : null;

        if ($useSubstitute) {
            if ($substituteId === null) {
                throw ValidationException::withMessages([
                    'substitute_driver_party_id' => 'Substitute driver is required when using a substitute.',
                ]);
            }
            $this->assertDriverParty($company, $branch, $substituteId, 'substitute_driver_party_id');
            $driverId = $substituteId;
        } else {
            if ($primaryDriverId === null || $primaryDriverId === '') {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Selected vehicle has no primary driver. Assign a primary driver or use a substitute.',
                ]);
            }
            $this->assertDriverParty($company, $branch, $primaryDriverId, 'vehicle_id');
            $driverId = $primaryDriverId;
            $substituteId = null;
        }

        $expectedEnd = filled($payload['expected_end_at'] ?? null)
            ? (string) $payload['expected_end_at']
            : null;

        try {
            return $this->auditedTransaction->run(function () use (
                $lorryReceipt,
                $actor,
                $company,
                $branch,
                $vehicle,
                $driverId,
                $primaryDriverId,
                $substituteId,
                $expectedEnd,
            ): Trip {
                return $this->withTenant($company, $branch, function () use (
                    $lorryReceipt,
                    $actor,
                    $company,
                    $branch,
                    $vehicle,
                    $driverId,
                    $primaryDriverId,
                    $substituteId,
                    $expectedEnd,
                ): Trip {
                    $existing = Trip::query()
                        ->where('company_id', $company->id)
                        ->where('lorry_receipt_id', $lorryReceipt->id)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->exists();

                    if ($existing) {
                        throw new TripConflictException('This OwnFleet LR already has a Trip.');
                    }

                    $trip = Trip::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'lorry_receipt_id' => $lorryReceipt->id,
                        'status' => TripStatus::Pending,
                        'vehicle_id' => $vehicle->id,
                        'driver_party_id' => $driverId,
                        'primary_driver_party_id' => $primaryDriverId,
                        'substitute_driver_party_id' => $substituteId,
                        'expected_end_at' => $expectedEnd,
                        'created_by_user_id' => $actor->id,
                    ]);

                    $this->audit->write(
                        AuditAction::Created,
                        Trip::class,
                        $trip->id,
                        remark: 'Create OwnFleet Trip from LR '.$lorryReceipt->lr_number,
                        before: null,
                        after: [
                            'lorry_receipt_id' => $lorryReceipt->id,
                            'vehicle_id' => $vehicle->id,
                            'driver_party_id' => $driverId,
                            'status' => TripStatus::Pending->value,
                        ],
                    );

                    return $trip->fresh(['lorryReceipt', 'vehicle', 'driver']) ?? $trip;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new TripConflictException('This OwnFleet LR already has a Trip.', previous: $e);
        }
    }

    public function findForBranch(Company $company, Branch $branch, string $tripId): Trip
    {
        $this->ensureTenantMatches($company, $branch);

        $trip = Trip::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($tripId)
            ->whereNull('deleted_at')
            ->with(['lorryReceipt', 'vehicle', 'driver', 'primaryDriver', 'substituteDriver', 'pickups.station', 'drops.station', 'allocations'])
            ->first();

        if ($trip === null) {
            throw new TripDomainException('Trip not found.');
        }

        return $trip;
    }

    public function tripForLorryReceipt(LorryReceipt $lorryReceipt): ?Trip
    {
        return Trip::query()
            ->where('company_id', $lorryReceipt->company_id)
            ->where('lorry_receipt_id', $lorryReceipt->id)
            ->whereNull('deleted_at')
            ->first();
    }

    private function assertCanCreate(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::TRIP_CREATE, $company, $branch)) {
            throw new TripAuthorizationException('Not authorized to create OwnFleet Trips.');
        }
    }

    private function assertOwnFleetEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::OwnFleet)) {
            throw new TripDomainException('OwnFleet module is not entitled for this company.');
        }
    }

    private function assertLorryReceiptEligible(LorryReceipt $lorryReceipt): void
    {
        if ($lorryReceipt->trashed()) {
            throw new TripDomainException('Cannot create a Trip for a deleted LR.');
        }

        if ($lorryReceipt->workflow_context !== LrWorkflowContext::OwnFleet) {
            throw ValidationException::withMessages([
                'lorry_receipt_id' => 'Only OwnFleet Lorry Receipts can create an OwnFleet Trip.',
            ]);
        }

        if ($lorryReceipt->status !== LrStatus::Pending) {
            throw ValidationException::withMessages([
                'lorry_receipt_id' => 'Trip can only be created from a pending OwnFleet LR.',
            ]);
        }
    }

    private function assertDriverParty(Company $company, Branch $branch, string $partyId, string $field): void
    {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                $field => 'Driver party was not found.',
            ]);
        }

        $partyVisible = $party->branch_id === null || $party->branch_id === $branch->id;
        if (! $partyVisible) {
            throw ValidationException::withMessages([
                $field => 'Driver is not visible in this branch.',
            ]);
        }

        $hasDriverRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasDriverRole) {
            throw ValidationException::withMessages([
                $field => 'Selected party must have an active Driver role.',
            ]);
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new TripDomainException('Branch does not belong to company.');
        }
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
