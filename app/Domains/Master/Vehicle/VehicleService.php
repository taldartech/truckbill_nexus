<?php

namespace App\Domains\Master\Vehicle;

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Master\TyreLayout\TyreLayoutService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\OwnFleet\Driver\VehicleDriverAssignmentService;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Database\CaseInsensitiveLike;
use App\Support\Vehicle\IndianVehicleRegistration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VehicleService
{
    /**
     * Technical specifications captured only for Own (OwnFleet) and unclassified vehicles.
     * Market vehicles carry Truck Number, Truck Owner, Truck Type, Driver and Registration Date.
     *
     * @var list<string>
     */
    public const OWN_FLEET_ONLY_FIELDS = [
        'vehicle_type',
        'make',
        'model',
        'manufacture_year',
        'chassis_number',
        'engine_number',
        'capacity_mt',
    ];

    private readonly CompanyCapabilityResolver $capabilities;

    private readonly VehicleDriverAssignmentService $driverAssignments;

    public function __construct(
        private readonly MasterAccess $access = new MasterAccess,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly TyreLayoutService $tyreLayouts = new TyreLayoutService,
        ?CompanyCapabilityResolver $capabilities = null,
        ?VehicleDriverAssignmentService $driverAssignments = null,
    ) {
        $this->capabilities = $capabilities ?? app(CompanyCapabilityResolver::class);
        $this->driverAssignments = $driverAssignments ?? app(VehicleDriverAssignmentService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        User $actor,
        Company $company,
        ?Branch $branch,
        array $attributes,
        string $remark = 'Create vehicle',
        ?Branch $visibilityBranch = null,
    ): Vehicle {
        $this->access->assertCanMutate($actor, $company, $branch);
        $this->assertBranchBelongs($company, $branch);
        $attributes['registration_number'] = IndianVehicleRegistration::assertValid($attributes['registration_number'] ?? '');
        $this->assertRegistrationUnique($company, (string) $attributes['registration_number']);
        $panelBranch = $visibilityBranch ?? $branch;
        $normalized = $this->normalizeOwnershipAttributes($company, $panelBranch, $attributes);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $panelBranch, $attributes, $normalized, $remark): Vehicle {
            return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $company, $branch, $panelBranch, $attributes, $normalized, $remark): Vehicle {
                $technical = $normalized['ownership'] === VehicleOwnership::Market
                    ? []
                    : collect($attributes)->only(self::OWN_FLEET_ONLY_FIELDS)->all();

                $vehicle = Vehicle::query()->create([
                    ...$technical,
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch?->id,
                    'registration_number' => strtoupper(trim((string) $attributes['registration_number'])),
                    'registration_date' => $attributes['registration_date'] ?? null,
                    'is_active' => $attributes['is_active'] ?? true,
                    'ownership' => $normalized['ownership'],
                    'truck_type_id' => $normalized['truck_type_id'],
                    'tyre_layout_id' => $normalized['tyre_layout_id'],
                    'owner_party_id' => $normalized['owner_party_id'],
                    'primary_driver_party_id' => $normalized['primary_driver_party_id'],
                ]);

                if ($vehicle->ownership === VehicleOwnership::Own && $vehicle->primary_driver_party_id !== null) {
                    $this->driverAssignments->syncFromVehicleMaster(
                        actor: $actor,
                        company: $company,
                        branch: $panelBranch,
                        vehicle: $vehicle,
                        previousDriverId: null,
                        newDriverId: $vehicle->primary_driver_party_id,
                        remark: $remark,
                    );
                }

                $this->audit->write(
                    AuditAction::Created,
                    Vehicle::class,
                    $vehicle->id,
                    remark: $remark,
                    before: null,
                    after: $vehicle->toArray(),
                );

                return $vehicle;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        User $actor,
        Vehicle $vehicle,
        array $attributes,
        string $remark,
        ?Branch $visibilityBranch = null,
    ): Vehicle {
        $company = $vehicle->company()->firstOrFail();
        $branch = $vehicle->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($vehicle->branch_id)->first()
            : null;

        $this->access->assertCanEdit($actor, $company, $branch);

        if (isset($attributes['registration_number'])) {
            $attributes['registration_number'] = IndianVehicleRegistration::assertValid($attributes['registration_number']);
            $this->assertRegistrationUnique(
                $company,
                (string) $attributes['registration_number'],
                $vehicle->id,
            );
        }

        $merged = array_merge([
            'ownership' => $vehicle->ownership?->value,
            'truck_type_id' => $vehicle->truck_type_id,
            'tyre_layout_id' => $vehicle->tyre_layout_id,
            'owner_party_id' => $vehicle->owner_party_id,
            'primary_driver_party_id' => $vehicle->primary_driver_party_id,
        ], $attributes);

        $panelBranch = $visibilityBranch
            ?? $branch
            ?? Branch::query()->where('company_id', $company->id)->orderBy('created_at')->first();
        $normalized = $this->normalizeOwnershipAttributes($company, $panelBranch, $merged, requireOwnership: array_key_exists('ownership', $attributes) || $vehicle->ownership !== null);

        return $this->auditedTransaction->run(function () use ($actor, $company, $branch, $panelBranch, $vehicle, $attributes, $normalized, $remark): Vehicle {
            return $this->access->withTenant($actor, $company, $branch, function () use ($actor, $company, $panelBranch, $vehicle, $attributes, $normalized, $remark): Vehicle {
                $before = $vehicle->toArray();
                $previousDriverId = $vehicle->primary_driver_party_id;
                $editable = ['registration_number', 'registration_date', 'is_active'];
                if ($normalized['ownership'] !== VehicleOwnership::Market) {
                    $editable = [...$editable, ...self::OWN_FLEET_ONLY_FIELDS];
                }
                $vehicle->fill(collect($attributes)->only($editable)->all());

                $vehicle->ownership = $normalized['ownership'];
                $vehicle->truck_type_id = $normalized['truck_type_id'];
                $vehicle->tyre_layout_id = $normalized['tyre_layout_id'];
                $vehicle->owner_party_id = $normalized['owner_party_id'];
                $vehicle->primary_driver_party_id = $normalized['primary_driver_party_id'];

                if ($vehicle->isDirty('registration_number')) {
                    $vehicle->registration_number = strtoupper(trim((string) $vehicle->registration_number));
                }

                $vehicle->save();

                $this->driverAssignments->syncFromVehicleMaster(
                    actor: $actor,
                    company: $company,
                    branch: $panelBranch,
                    vehicle: $vehicle,
                    previousDriverId: $previousDriverId,
                    newDriverId: $vehicle->primary_driver_party_id,
                    remark: $remark,
                );

                $this->audit->write(
                    AuditAction::Updated,
                    Vehicle::class,
                    $vehicle->id,
                    remark: $remark,
                    before: $before,
                    after: $vehicle->fresh()?->toArray(),
                );

                return $vehicle->fresh() ?? $vehicle;
            });
        });
    }

    public function softDelete(User $actor, Vehicle $vehicle, string $remark): void
    {
        $company = $vehicle->company()->firstOrFail();
        $branch = $vehicle->branch_id !== null
            ? Branch::query()->where('company_id', $company->id)->whereKey($vehicle->branch_id)->first()
            : null;

        $this->access->assertCanDelete($actor, $company, $branch);

        $this->auditedTransaction->run(function () use ($actor, $company, $branch, $vehicle, $remark): void {
            $this->access->withTenant($actor, $company, $branch, function () use ($vehicle, $remark): void {
                $before = $vehicle->toArray();
                $vehicle->delete();

                $this->audit->write(
                    AuditAction::Deleted,
                    Vehicle::class,
                    $vehicle->id,
                    remark: $remark,
                    before: $before,
                    after: null,
                );
            });
        });
    }

    /**
     * @return LengthAwarePaginator<int, Vehicle>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        ?string $query = null,
        ?string $vehicleType = null,
        ?bool $isActive = null,
        ?string $ownership = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $this->access->assertCanView($actor, $company, $branch);

        $builder = Vehicle::query()
            ->visibleInBranch($company->id, $branch->id)
            ->with(['truckType', 'tyreLayout', 'ownerParty', 'primaryDriver'])
            ->orderBy('registration_number');

        if ($query !== null && trim($query) !== '') {
            CaseInsensitiveLike::whereAnyContains($builder, [
                'registration_number',
                'make',
                'model',
                'chassis_number',
            ], $query);
        }

        if ($vehicleType !== null && $vehicleType !== '') {
            $builder->where('vehicle_type', $vehicleType);
        }

        if ($isActive !== null) {
            $builder->where('is_active', $isActive);
        }

        if ($ownership !== null && $ownership !== '') {
            $enum = VehicleOwnership::tryFrom($ownership);
            if ($enum === null) {
                throw ValidationException::withMessages(['ownership' => 'Invalid ownership filter.']);
            }
            $builder->where('ownership', $enum->value);
        }

        return $builder->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     ownership: ?VehicleOwnership,
     *     truck_type_id: ?string,
     *     tyre_layout_id: ?string,
     *     owner_party_id: ?string,
     *     primary_driver_party_id: ?string
     * }
     */
    private function normalizeOwnershipAttributes(
        Company $company,
        ?Branch $branch,
        array $attributes,
        bool $requireOwnership = true,
    ): array {
        $rawOwnership = $attributes['ownership'] ?? null;
        $ownership = null;

        if ($rawOwnership !== null && $rawOwnership !== '') {
            $ownership = VehicleOwnership::tryFrom((string) $rawOwnership);
            if ($ownership === null) {
                throw ValidationException::withMessages([
                    'ownership' => 'Ownership must be Own or Market.',
                ]);
            }
        } elseif ($requireOwnership) {
            throw ValidationException::withMessages([
                'ownership' => 'Ownership (Own or Market) is required.',
            ]);
        }

        $truckTypeId = $this->optionalUuid($attributes['truck_type_id'] ?? null);
        $tyreLayoutId = $this->optionalUuid($attributes['tyre_layout_id'] ?? null);
        $ownerPartyId = $this->optionalUuid($attributes['owner_party_id'] ?? null);
        $primaryDriverId = $this->optionalUuid($attributes['primary_driver_party_id'] ?? null);

        if ($ownership !== null && ! $this->capabilities->allowsVehicleOwnership($company, $ownership)) {
            $requiredCap = $ownership === VehicleOwnership::Own
                ? CompanyCapabilities::VEHICLE_OWNERSHIP_OWN
                : CompanyCapabilities::VEHICLE_OWNERSHIP_MARKET;

            throw ValidationException::withMessages([
                'ownership' => $this->capabilities->denyMessage($company, $requiredCap),
            ]);
        }

        if ($ownership === VehicleOwnership::Own) {
            if ($truckTypeId === null) {
                throw ValidationException::withMessages([
                    'truck_type_id' => 'Truck Type is required for Own vehicles.',
                ]);
            }
            if ($tyreLayoutId === null) {
                throw ValidationException::withMessages([
                    'tyre_layout_id' => 'Tyre Layout is required for Own vehicles.',
                ]);
            }
            $this->assertCatalogue($company, $truckTypeId, OwnerCatalogueType::TruckType, 'truck_type_id');
            $this->tyreLayouts->assertForCompany($company, $tyreLayoutId, 'tyre_layout_id');
            $ownerPartyId = null;
        } elseif ($ownership === VehicleOwnership::Market) {
            // Truck Owner is optional on the master; Bilty / Lorry Hire enforce the payee.
            if ($ownerPartyId !== null) {
                $this->assertTransporterParty($company, $branch, $ownerPartyId);
            }
            if ($truckTypeId !== null) {
                $this->assertCatalogue($company, $truckTypeId, OwnerCatalogueType::TruckType, 'truck_type_id');
            }
            $tyreLayoutId = null;
        } else {
            // Unclassified historical-style create: clear ownership-dependent FKs.
            $truckTypeId = null;
            $tyreLayoutId = null;
            $ownerPartyId = null;
        }

        if ($primaryDriverId !== null) {
            $this->assertDriverParty($company, $branch, $primaryDriverId);
        }

        return [
            'ownership' => $ownership,
            'truck_type_id' => $truckTypeId,
            'tyre_layout_id' => $tyreLayoutId,
            'owner_party_id' => $ownerPartyId,
            'primary_driver_party_id' => $primaryDriverId,
        ];
    }

    private function assertCatalogue(
        Company $company,
        string $itemId,
        OwnerCatalogueType $type,
        string $field,
    ): void {
        $exists = OwnerCatalogueItem::query()
            ->whereKey($itemId)
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', $type->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                $field => 'Selected catalogue item is invalid.',
            ]);
        }
    }

    private function assertTransporterParty(Company $company, ?Branch $branch, string $partyId): void
    {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'owner_party_id' => 'Vehicle Owner party was not found.',
            ]);
        }

        $this->assertRelatedPartyVisible(
            $company,
            $branch,
            $party,
            'owner_party_id',
            'The registered vehicle owner is not available for transactions in this branch. Please contact an authorized administrator to review the vehicle details.',
        );

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Transporter->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([
                'owner_party_id' => 'Vehicle Owner must have an active Transporter role.',
            ]);
        }
    }

    private function assertDriverParty(Company $company, ?Branch $branch, string $partyId): void
    {
        $party = Party::query()
            ->where('company_id', $company->id)
            ->whereKey($partyId)
            ->whereNull('deleted_at')
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'primary_driver_party_id' => 'Primary driver party was not found.',
            ]);
        }

        $this->assertRelatedPartyVisible(
            $company,
            $branch,
            $party,
            'primary_driver_party_id',
            'Primary driver is not available in this branch.',
        );

        $hasRole = PartyRole::query()
            ->where('company_id', $company->id)
            ->where('party_id', $party->id)
            ->where('role', PartyRoleType::Driver->value)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->exists();

        if (! $hasRole) {
            throw ValidationException::withMessages([
                'primary_driver_party_id' => 'Selected party must have an active Driver role.',
            ]);
        }
    }

    private function assertRelatedPartyVisible(
        Company $company,
        ?Branch $visibilityBranch,
        Party $party,
        string $field,
        string $message,
    ): void {
        if ($visibilityBranch !== null) {
            $visible = Party::query()
                ->visibleInBranch($company->id, $visibilityBranch->id)
                ->whereKey($party->id)
                ->whereNull('deleted_at')
                ->exists();

            if (! $visible) {
                throw ValidationException::withMessages([$field => $message]);
            }

            return;
        }

        // Shared/company-wide vehicle creates without a panel branch may only
        // reference company-wide parties (branch_id null).
        if ($party->branch_id !== null) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function assertRegistrationUnique(Company $company, string $registration, ?string $ignoreId = null): void
    {
        $normalized = IndianVehicleRegistration::normalize($registration);
        if ($normalized === '') {
            throw ValidationException::withMessages(['registration_number' => 'Registration number is required.']);
        }

        $exists = Vehicle::query()
            ->where('company_id', $company->id)
            ->where('registration_number', $normalized)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'registration_number' => 'A vehicle with this registration already exists in the company.',
            ]);
        }
    }

    private function assertBranchBelongs(Company $company, ?Branch $branch): void
    {
        if ($branch !== null && $branch->company_id !== $company->id) {
            throw new MasterDomainException('Branch does not belong to company.');
        }
    }

    private function optionalUuid(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
