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
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OwnerCatalogueItem;
use App\Models\OwnerTyreLayout;
use App\Models\User;
use App\Models\Vehicle;
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

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly VehicleService $vehicles,
        private readonly DocumentAttachmentService $documents,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

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

        $ownershipInputs = $validated['ownership'] ?? null;
        $ownerships = null;
        if (is_array($ownershipInputs)) {
            $ownerships = [];
            foreach ($ownershipInputs as $ownershipInput) {
                $ownerships[] = $ownershipInput instanceof VehicleOwnership
                    ? $ownershipInput
                    : VehicleOwnership::from((string) $ownershipInput);
            }
        }

        $options = app(VehicleOptionQuery::class)->forBranch(
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

        return Inertia::render('branch/masters/vehicles/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'vehicle' => null,
            'formOptions' => $this->formOptions($company, $branch),
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.vehicles.index', absolute: false),
                'store' => route('branch.vehicles.store', absolute: false),
                'truckTypesStoreUrl' => route('branch.truck-types.store', absolute: false),
                'tyreLayoutsStoreUrl' => route('branch.tyre-layouts.store', absolute: false),
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

        return Inertia::render('branch/masters/vehicles/show', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'vehicle' => $this->vehiclePayload($vehicle),
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

        return Inertia::render('branch/masters/vehicles/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'vehicle' => $this->vehiclePayload($vehicle),
            'formOptions' => $this->formOptions($company, $branch),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.vehicles.index', absolute: false),
                'show' => route('branch.vehicles.show', [$vehicle], absolute: false),
                'update' => route('branch.vehicles.update', [$vehicle], absolute: false),
                'truckTypesStoreUrl' => route('branch.truck-types.store', absolute: false),
                'tyreLayoutsStoreUrl' => route('branch.tyre-layouts.store', absolute: false),
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
        $truckTypes = OwnerCatalogueItem::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('catalogue_type', OwnerCatalogueType::TruckType->value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        $tyreLayouts = OwnerTyreLayout::query()
            ->where('owner_user_id', $company->owner_user_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        $partyOptions = app(PartyOptionQuery::class);

        return [
            'ownershipOptions' => collect(VehicleOwnership::cases())->map(fn (VehicleOwnership $case): array => [
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
            'transporters' => $partyOptions->forRoles($company, $branch, PartyRoleType::Transporter),
            'drivers' => $partyOptions->forRoles($company, $branch, PartyRoleType::Driver),
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
