<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Master\Vehicle\VehicleOptionQuery;
use App\Domains\Master\Vehicle\VehicleService;
use App\Domains\OwnFleet\Driver\VehicleDriverAssignmentService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\OwnerTyreLayout;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDriverAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    private readonly CompanyCapabilityResolver $capabilities;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly VehicleService $vehicles,
        private readonly DocumentAttachmentService $documents,
        ?CompanyCapabilityResolver $capabilities = null,
    ) {
        $this->capabilities = $capabilities ?? app(CompanyCapabilityResolver::class);
    }

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);
        $permissions['can_create'] = $permissions['can_create']
            && $this->capabilities->allowsMutate($company, CompanyCapabilities::MASTERS_VEHICLES)
            && $this->capabilities->allowedVehicleOwnerships($company) !== [];

        try {
            $active = $request->query('active');
            $page = $this->vehicles->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $request->string('q')->toString() ?: null,
                isActive: $active === null || $active === '' ? null : filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                perPage: min(100, max(1, (int) $request->input('per_page', 15))),
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return Inertia::render('branch/masters/vehicles/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'active' => $request->string('active')->toString(),
            ],
            'vehicles' => [
                'data' => collect($page->items())->map(fn (Vehicle $vehicle): array => $this->vehiclePayload($vehicle))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.vehicles.index', absolute: false),
                'create' => route('branch.vehicles.create', absolute: false),
                'store' => route('branch.vehicles.store', absolute: false),
            ]),
        ]);
    }

    public function options(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'selected_id' => ['nullable', 'uuid'],
            'ownership' => ['nullable', 'array', 'max:4'],
            'ownership.*' => [Rule::enum(VehicleOwnership::class)],
            'active_only' => ['nullable', 'boolean'],
        ]);

        $allowedOwnerships = $this->capabilities->allowedVehicleOwnerships($company, forMutation: false);
        $ownershipInputs = $validated['ownership'] ?? null;
        if (is_array($ownershipInputs)) {
            $requested = [];
            foreach ($ownershipInputs as $ownershipInput) {
                $requested[] = $ownershipInput instanceof VehicleOwnership
                    ? $ownershipInput
                    : VehicleOwnership::from((string) $ownershipInput);
            }
            $ownerships = array_values(array_uintersect(
                $requested,
                $allowedOwnerships,
                fn (VehicleOwnership $a, VehicleOwnership $b): int => $a->value <=> $b->value,
            ));
        } else {
            $ownerships = $allowedOwnerships;
        }

        $options = $ownerships === []
            ? []
            : app(VehicleOptionQuery::class)->forBranch(
                company: $company,
                branch: $branch,
                limit: (int) ($validated['limit'] ?? 50),
                query: $validated['q'] ?? null,
                selectedId: $validated['selected_id'] ?? null,
                ownerships: $ownerships,
                activeOnly: array_key_exists('active_only', $validated)
                    ? (bool) $validated['active_only']
                    : true,
            );

        return response()->json([
            'data' => $options,
            'meta' => [
                'count' => count($options),
                'limit' => (int) ($validated['limit'] ?? 50),
                'q' => $validated['q'] ?? '',
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create']) {
            abort(403);
        }

        if ($this->capabilities->allowedVehicleOwnerships($company) === []) {
            abort(403, $this->capabilities->denyMessage($company, CompanyCapabilities::MASTERS_VEHICLES));
        }

        $ownsAllowed = $this->capabilities->allowsMutate($company, CompanyCapabilities::VEHICLE_OWNERSHIP_OWN);
        $driversEnabled = $this->capabilities->allowsMutate($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS);

        return Inertia::render('branch/masters/vehicles/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'vehicle' => null,
            'driversEnabled' => $driversEnabled,
            'formOptions' => $this->formOptions($company, $branch),
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.vehicles.index', absolute: false),
                'store' => route('branch.vehicles.store', absolute: false),
                'truckTypesStoreUrl' => $ownsAllowed ? route('branch.truck-types.store', absolute: false) : null,
                'tyreLayoutsStoreUrl' => $ownsAllowed ? route('branch.tyre-layouts.store', absolute: false) : null,
                'partiesOptionsUrl' => route('branch.parties.options', absolute: false),
                'partiesStoreUrl' => route('branch.parties.store', absolute: false),
            ]),
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'registration_number' => ['required', 'string', 'max:32'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'manufacture_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'chassis_number' => ['nullable', 'string', 'max:100'],
            'engine_number' => ['nullable', 'string', 'max:100'],
            'capacity_mt' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'ownership' => ['required', Rule::enum(VehicleOwnership::class)],
            'truck_type_id' => ['nullable', 'uuid'],
            'tyre_layout_id' => ['nullable', 'uuid'],
            'owner_party_id' => ['nullable', 'uuid'],
            'primary_driver_party_id' => ['nullable', 'uuid'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $requestedOwnership = VehicleOwnership::from((string) $validated['ownership']);
        if (! $this->capabilities->allowsVehicleOwnership($company, $requestedOwnership)) {
            $cap = $requestedOwnership === VehicleOwnership::Own
                ? CompanyCapabilities::VEHICLE_OWNERSHIP_OWN
                : CompanyCapabilities::VEHICLE_OWNERSHIP_MARKET;
            abort(403, $this->capabilities->denyMessage($company, $cap));
        }

        $scopeBranch = app(MasterDataVisibilityResolver::class)
            ->scopeBranchForCreate($company, $branch);

        try {
            $vehicle = $this->vehicles->create(
                $actor,
                $company,
                $scopeBranch,
                $validated,
                $validated['remark'] ?? 'Create vehicle',
                $branch,
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            $options = app(VehicleOptionQuery::class)->forBranch(
                company: $company,
                branch: $branch,
                limit: 1,
                selectedId: $vehicle->id,
                activeOnly: false,
            );

            return response()->json([
                'data' => $options[0] ?? [
                    'id' => $vehicle->id,
                    'registration_number' => $vehicle->registration_number,
                    'ownership' => $vehicle->ownership?->value,
                    'ownership_label' => $vehicle->ownership?->label(),
                    'label' => $vehicle->registration_number,
                    'owner_party_id' => $vehicle->owner_party_id,
                    'owner_name' => null,
                    'owner_declaration_missing' => false,
                    'primary_driver_party_id' => $vehicle->primary_driver_party_id,
                    'primary_driver_name' => null,
                ],
            ], 201);
        }

        return redirect()
            ->route('branch.vehicles.show', [$vehicle])
            ->with('success', 'Vehicle created.');
    }

    public function show(Request $request, Vehicle $vehicle, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $this->vehicles->search($actor, $company, $branch, perPage: 1);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        $canViewDrivers = $vehicle->ownership === VehicleOwnership::Own
            && $this->capabilities->allowsRead($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS);
        $canManageDrivers = $vehicle->ownership === VehicleOwnership::Own
            && $this->capabilities->allowsMutate($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS)
            && $permissions['can_edit'];

        $driverAssignmentService = $canViewDrivers ? app(VehicleDriverAssignmentService::class) : null;
        $today = now(filled($company->timezone) ? (string) $company->timezone : 'Asia/Kolkata')->toDateString();
        $activeAssignment = $driverAssignmentService?->currentPrimaryForVehicle($company, $vehicle, $today);
        $assignmentHistory = $driverAssignmentService !== null
            ? $driverAssignmentService->historyForVehicle($company, $vehicle)->take(8)
            : collect();

        return Inertia::render('branch/masters/vehicles/show', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'vehicle' => $this->vehiclePayload($vehicle),
            'driverManagement' => $canViewDrivers ? [
                'can_manage' => $canManageDrivers,
                'can_create_party' => $this->capabilities->allowsMutate($company, CompanyCapabilities::MASTERS_PARTIES)
                    && $permissions['can_create'],
                'active_assignment' => $activeAssignment !== null ? [
                    'id' => $activeAssignment->id,
                    'driver_party_id' => $activeAssignment->driver_party_id,
                    'driver_name' => $activeAssignment->driver?->name,
                    'driver_contact_number' => $activeAssignment->driver?->contact_number,
                    'assigned_from' => $activeAssignment->assigned_from->toDateString(),
                    'assigned_to' => $activeAssignment->assigned_to?->toDateString(),
                ] : null,
                'history' => $assignmentHistory->map(fn (VehicleDriverAssignment $row): array => [
                    'id' => $row->id,
                    'driver_name' => $row->driver !== null ? $row->driver->name : '—',
                    'assigned_from' => $row->assigned_from->toDateString(),
                    'assigned_to' => $row->assigned_to?->toDateString(),
                    'status' => $row->statusOn($today),
                    'remarks' => $row->remarks,
                ])->values()->all(),
                'driver_options' => $canManageDrivers
                    ? app(PartyOptionQuery::class)->forRoles($company, $branch, PartyRoleType::Driver, 200)
                    : [],
            ] : null,
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'vehicle',
                $vehicle->id,
                $this->documents,
                $permissions,
            ),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.vehicles.index', absolute: false),
                'edit' => route('branch.vehicles.edit', [$vehicle], absolute: false),
                'update' => route('branch.vehicles.update', [$vehicle], absolute: false),
                'destroy' => route('branch.vehicles.destroy', [$vehicle], absolute: false),
                'driverAssign' => $canManageDrivers ? route('branch.vehicle-drivers.assign', [$vehicle], absolute: false) : null,
                'driverRemove' => $canManageDrivers ? route('branch.vehicle-drivers.remove', [$vehicle], absolute: false) : null,
                'driverHistory' => $canViewDrivers ? route('branch.vehicle-drivers.history', [$vehicle], absolute: false) : null,
                'vehicleDriversIndex' => $canViewDrivers ? route('branch.vehicle-drivers.index', absolute: false) : null,
                'partiesOptionsUrl' => route('branch.parties.options', absolute: false),
                'partiesStoreUrl' => route('branch.parties.store', absolute: false),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'vehicle',
                    'parentId' => $vehicle->id,
                ], absolute: false),
            ]),
        ]);
    }

    public function edit(Request $request, Vehicle $vehicle, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_edit']) {
            abort(403);
        }

        $ownsAllowed = $this->capabilities->allowsMutate($company, CompanyCapabilities::VEHICLE_OWNERSHIP_OWN);
        $driversEnabled = $this->capabilities->allowsMutate($company, CompanyCapabilities::OWNFLEET_VEHICLE_DRIVERS);

        return Inertia::render('branch/masters/vehicles/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'vehicle' => $this->vehiclePayload($vehicle),
            'driversEnabled' => $driversEnabled,
            'formOptions' => $this->formOptions($company, $branch),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.vehicles.index', absolute: false),
                'show' => route('branch.vehicles.show', [$vehicle], absolute: false),
                'update' => route('branch.vehicles.update', [$vehicle], absolute: false),
                'truckTypesStoreUrl' => $ownsAllowed ? route('branch.truck-types.store', absolute: false) : null,
                'tyreLayoutsStoreUrl' => $ownsAllowed ? route('branch.tyre-layouts.store', absolute: false) : null,
                'partiesOptionsUrl' => route('branch.parties.options', absolute: false),
                'partiesStoreUrl' => route('branch.parties.store', absolute: false),
            ]),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle, Company $company, Branch $branch): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'registration_number' => ['sometimes', 'string', 'max:32'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'manufacture_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'chassis_number' => ['nullable', 'string', 'max:100'],
            'engine_number' => ['nullable', 'string', 'max:100'],
            'capacity_mt' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'ownership' => ['required', Rule::enum(VehicleOwnership::class)],
            'truck_type_id' => ['nullable', 'uuid'],
            'tyre_layout_id' => ['nullable', 'uuid'],
            'owner_party_id' => ['nullable', 'uuid'],
            'primary_driver_party_id' => ['nullable', 'uuid'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $requestedOwnership = VehicleOwnership::from((string) $validated['ownership']);
        if (! $this->capabilities->allowsVehicleOwnership($company, $requestedOwnership)) {
            $cap = $requestedOwnership === VehicleOwnership::Own
                ? CompanyCapabilities::VEHICLE_OWNERSHIP_OWN
                : CompanyCapabilities::VEHICLE_OWNERSHIP_MARKET;
            abort(403, $this->capabilities->denyMessage($company, $cap));
        }

        try {
            $this->vehicles->update($actor, $vehicle, $validated, $validated['remark'], $branch);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.vehicles.show', [$vehicle])
            ->with('success', 'Vehicle updated.');
    }

    public function destroy(Request $request, Vehicle $vehicle, Company $company, Branch $branch): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $branch, $vehicle);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->vehicles->softDelete($actor, $vehicle, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.vehicles.index')
            ->with('success', 'Vehicle deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function vehiclePayload(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['truckType', 'tyreLayout', 'ownerParty', 'primaryDriver']);

        return [
            'id' => $vehicle->id,
            'registration_number' => $vehicle->registration_number,
            'vehicle_type' => $vehicle->vehicle_type,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'manufacture_year' => $vehicle->manufacture_year,
            'chassis_number' => $vehicle->chassis_number,
            'engine_number' => $vehicle->engine_number,
            'capacity_mt' => $vehicle->capacity_mt,
            'is_active' => (bool) $vehicle->is_active,
            'ownership' => $vehicle->ownership?->value,
            'truck_type_id' => $vehicle->truck_type_id,
            'tyre_layout_id' => $vehicle->tyre_layout_id,
            'owner_party_id' => $vehicle->owner_party_id,
            'primary_driver_party_id' => $vehicle->primary_driver_party_id,
            'truck_type_name' => $vehicle->truckType?->name,
            'tyre_layout_name' => $vehicle->tyreLayout?->name,
            'owner_party_name' => $vehicle->ownerParty?->name,
            'primary_driver_name' => $vehicle->primaryDriver?->name,
            'scope' => $vehicle->branch_id === null ? 'shared' : 'branch',
            'branch_id' => $vehicle->branch_id,
        ];
    }

    /**
     * @return array{
     *     ownershipOptions: array<int, array{value: string, label: string}>,
     *     truckTypes: array<int, array{id: string, name: string}>,
     *     tyreLayouts: array<int, array{id: string, name: string}>,
     *     transporters: array<int, array{id: string, name: string}>,
     *     drivers: array<int, array{id: string, name: string}>
     * }
     */
    private function formOptions(Company $company, Branch $branch): array
    {
        $allowedOwnerships = $this->capabilities->allowedVehicleOwnerships($company);
        $allowsOwn = in_array(VehicleOwnership::Own, $allowedOwnerships, true);
        $allowsMarket = in_array(VehicleOwnership::Market, $allowedOwnerships, true);

        $truckTypes = $allowsOwn
            ? OwnerCatalogueItem::query()
                ->where('owner_user_id', $company->owner_user_id)
                ->where('catalogue_type', OwnerCatalogueType::TruckType->value)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        $tyreLayouts = $allowsOwn
            ? OwnerTyreLayout::query()
                ->where('owner_user_id', $company->owner_user_id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        $partyOptions = app(PartyOptionQuery::class);

        return [
            'ownershipOptions' => collect($allowedOwnerships)->map(fn (VehicleOwnership $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ])->values()->all(),
            'truckTypes' => $truckTypes->map(fn (OwnerCatalogueItem $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values()->all(),
            'tyreLayouts' => $tyreLayouts->map(fn (OwnerTyreLayout $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->values()->all(),
            'transporters' => $allowsMarket
                ? $partyOptions->forRoles($company, $branch, PartyRoleType::Transporter)
                : [],
            'drivers' => $allowsOwn
                ? $partyOptions->forRoles($company, $branch, PartyRoleType::Driver)
                : [],
        ];
    }

    private function assertVehicle(Company $company, Branch $branch, Vehicle $vehicle): void
    {
        if ($vehicle->company_id !== $company->id) {
            abort(404);
        }

        try {
            app(MasterAccess::class)
                ->assertRecordVisibleInBranch($company, $branch, Vehicle::class, $vehicle->id);
        } catch (MasterDomainException) {
            abort(404);
        }
    }
}
