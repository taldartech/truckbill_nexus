<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Station\StationService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\Station;
use App\Models\StationDefaultTransporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly StationService $stations,
        private readonly DocumentAttachmentService $documents,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $page = $this->stations->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $request->string('q')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 15))),
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        $defaults = StationDefaultTransporter::query()
            ->where('company_id', $company->id)
            ->where(function ($q) use ($branch): void {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->get()
            ->keyBy('station_id');

        return Inertia::render('branch/masters/stations/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'canManageOwnerStations' => $permissions['is_owner'],
            'filters' => ['q' => $request->string('q')->toString()],
            'stations' => [
                'data' => collect($page->items())->map(function (Station $station) use ($defaults): array {
                    $default = $defaults->get($station->id);

                    return [
                        'id' => $station->id,
                        'code' => $station->code,
                        'name' => $station->name,
                        'state' => $station->state,
                        'city' => $station->city,
                        'is_active' => (bool) $station->is_active,
                        'default_transporter_party_id' => $default?->default_transporter_party_id,
                    ];
                })->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ],
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.stations.index', [$company, $branch]),
                'create' => route('branch.stations.create', [$company, $branch]),
                'store' => route('branch.stations.store', [$company, $branch]),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['is_owner']) {
            abort(403, 'Only the company owner may create stations.');
        }

        return Inertia::render('branch/masters/stations/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'station' => null,
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.stations.index', [$company, $branch]),
                'store' => route('branch.stations.store', [$company, $branch]),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $station = $this->stations->create($actor, $company, $validated, $validated['remark'] ?? 'Create station');
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.stations.show', [$company, $branch, $station])
            ->with('success', 'Station created.');
    }

    public function show(Request $request, Company $company, Branch $branch, Station $station): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertStationVisible($company, $station);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $this->stations->search($actor, $company, $branch, perPage: 1);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        $default = StationDefaultTransporter::query()
            ->where('station_id', $station->id)
            ->where('company_id', $company->id)
            ->where(function ($q) use ($branch): void {
                $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->orderByRaw('branch_id nulls last')
            ->first();

        return Inertia::render('branch/masters/stations/show', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'canManageOwnerStations' => $permissions['is_owner'],
            'station' => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->name,
                'state' => $station->state,
                'city' => $station->city,
                'is_active' => (bool) $station->is_active,
                'default_transporter_party_id' => $default?->default_transporter_party_id,
            ],
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'station',
                $station->id,
                $this->documents,
                $permissions,
            ),
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.stations.index', [$company, $branch]),
                'edit' => route('branch.stations.edit', [$company, $branch, $station]),
                'update' => route('branch.stations.update', [$company, $branch, $station]),
                'destroy' => route('branch.stations.destroy', [$company, $branch, $station]),
                'set_default_transporter' => route('branch.stations.default-transporter', [$company, $branch, $station]),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'station',
                    'parentId' => $station->id,
                ]),
            ],
        ]);
    }

    public function edit(Request $request, Company $company, Branch $branch, Station $station): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertStationVisible($company, $station);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['is_owner']) {
            abort(403);
        }

        return Inertia::render('branch/masters/stations/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'station' => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->name,
                'state' => $station->state,
                'city' => $station->city,
                'is_active' => (bool) $station->is_active,
            ],
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.stations.index', [$company, $branch]),
                'show' => route('branch.stations.show', [$company, $branch, $station]),
                'update' => route('branch.stations.update', [$company, $branch, $station]),
            ],
        ]);
    }

    public function update(Request $request, Company $company, Branch $branch, Station $station): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertStationVisible($company, $station);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->stations->update($actor, $station, $company, $validated, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.stations.show', [$company, $branch, $station])
            ->with('success', 'Station updated.');
    }

    public function destroy(Request $request, Company $company, Branch $branch, Station $station): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertStationVisible($company, $station);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->stations->softDelete($actor, $station, $company, $validated['remark']);
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()
            ->route('branch.stations.index', [$company, $branch])
            ->with('success', 'Station deleted.');
    }

    public function setDefaultTransporter(Request $request, Company $company, Branch $branch, Station $station): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertStationVisible($company, $station);

        $validated = $request->validate([
            'transporter_party_id' => ['nullable', 'uuid'],
            'scope' => ['required', Rule::in(['company', 'branch'])],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $transporter = null;
        if (! empty($validated['transporter_party_id'])) {
            $transporter = Party::query()
                ->where('company_id', $company->id)
                ->whereKey($validated['transporter_party_id'])
                ->firstOrFail();
        }

        $scopeBranch = $validated['scope'] === 'branch' ? $branch : null;

        try {
            $this->stations->setDefaultTransporter(
                $actor,
                $station,
                $company,
                $scopeBranch,
                $transporter,
                $validated['remark'],
            );
        } catch (MasterDomainException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Default transporter updated.');
    }

    private function assertStationVisible(Company $company, Station $station): void
    {
        if ((int) $station->owner_user_id !== (int) $company->owner_user_id) {
            abort(404);
        }
    }
}
