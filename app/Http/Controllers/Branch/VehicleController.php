<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Vehicle\VehicleService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                ],
            ],
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.vehicles.index', [$company, $branch]),
                'create' => route('branch.vehicles.create', [$company, $branch]),
                'store' => route('branch.vehicles.store', [$company, $branch]),
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
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.vehicles.index', [$company, $branch]),
                'store' => route('branch.vehicles.store', [$company, $branch]),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
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
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.vehicles.show', [$company, $branch, $vehicle])
            ->with('success', 'Vehicle created.');
    }

    public function show(Request $request, Company $company, Branch $branch, Vehicle $vehicle): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $vehicle);
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
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.vehicles.index', [$company, $branch]),
                'edit' => route('branch.vehicles.edit', [$company, $branch, $vehicle]),
                'update' => route('branch.vehicles.update', [$company, $branch, $vehicle]),
                'destroy' => route('branch.vehicles.destroy', [$company, $branch, $vehicle]),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'vehicle',
                    'parentId' => $vehicle->id,
                ]),
            ],
        ]);
    }

    public function edit(Request $request, Company $company, Branch $branch, Vehicle $vehicle): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $vehicle);
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
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.vehicles.index', [$company, $branch]),
                'show' => route('branch.vehicles.show', [$company, $branch, $vehicle]),
                'update' => route('branch.vehicles.update', [$company, $branch, $vehicle]),
            ],
        ]);
    }

    public function update(Request $request, Company $company, Branch $branch, Vehicle $vehicle): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $vehicle);

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
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->vehicles->update($actor, $vehicle, $validated, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.vehicles.show', [$company, $branch, $vehicle])
            ->with('success', 'Vehicle updated.');
    }

    public function destroy(Request $request, Company $company, Branch $branch, Vehicle $vehicle): RedirectResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertVehicle($company, $vehicle);

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
            ->route('branch.vehicles.index', [$company, $branch])
            ->with('success', 'Vehicle deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function vehiclePayload(Vehicle $vehicle): array
    {
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
            'scope' => $vehicle->branch_id === null ? 'shared' : 'branch',
            'branch_id' => $vehicle->branch_id,
        ];
    }

    private function assertVehicle(Company $company, Vehicle $vehicle): void
    {
        if ($vehicle->company_id !== $company->id) {
            abort(404);
        }
    }
}
