<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Master\Party\PartyService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Party Master Inertia UI + JSON adapters (PO-P3-UI-AUTH).
 */
class PartyController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartyService $parties,
        private readonly DocumentAttachmentService $documents,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $page = $this->parties->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $request->string('q')->toString() ?: null,
                role: $request->string('role')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 15))),
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json($page);
        }

        return Inertia::render('branch/masters/parties/index', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'filters' => [
                'q' => $request->string('q')->toString(),
                'role' => $request->string('role')->toString(),
            ],
            'roleOptions' => collect(PartyRoleType::cases())->map(fn (PartyRoleType $role): array => [
                'value' => $role->value,
                'label' => str_replace('_', ' ', ucfirst($role->value)),
            ])->values()->all(),
            'parties' => [
                'data' => collect($page->items())->map(fn (Party $party): array => $this->partyListPayload($party))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.parties.index', [$company, $branch]),
                'create' => route('branch.parties.create', [$company, $branch]),
                'store' => route('branch.parties.store', [$company, $branch]),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create']) {
            abort(403, 'Not authorized to create parties.');
        }

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'party' => null,
            'roleOptions' => collect(PartyRoleType::cases())->map(fn (PartyRoleType $role): array => [
                'value' => $role->value,
                'label' => str_replace('_', ' ', ucfirst($role->value)),
            ])->values()->all(),
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.parties.index', [$company, $branch]),
                'store' => route('branch.parties.store', [$company, $branch]),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['shared', 'branch'])],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'is_gst_applicable' => ['sometimes', 'boolean'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.role' => ['required', Rule::enum(PartyRoleType::class)],
            'roles.*.attributes' => ['nullable', 'array'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $scopeBranch = $validated['scope'] === 'branch' ? $branch : null;

        try {
            $party = $this->parties->create(
                actor: $actor,
                company: $company,
                branch: $scopeBranch,
                attributes: $validated,
                roles: $validated['roles'],
                remark: $validated['remark'] ?? 'Create party',
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $party], 201);
        }

        return redirect()
            ->route('branch.parties.show', [$company, $branch, $party])
            ->with('success', 'Party created.');
    }

    public function show(Request $request, Company $company, Branch $branch, Party $party): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $party);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        try {
            $this->parties->search($actor, $company, $branch, perPage: 1);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        $party->load(['roles' => fn ($q) => $q->orderByDesc('created_at')]);

        return Inertia::render('branch/masters/parties/show', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'party' => $this->partyDetailPayload($party),
            'roleOptions' => collect(PartyRoleType::cases())->map(fn (PartyRoleType $role): array => [
                'value' => $role->value,
                'label' => str_replace('_', ' ', ucfirst($role->value)),
            ])->values()->all(),
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'party',
                $party->id,
                $this->documents,
                $permissions,
            ),
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.parties.index', [$company, $branch]),
                'edit' => route('branch.parties.edit', [$company, $branch, $party]),
                'update' => route('branch.parties.update', [$company, $branch, $party]),
                'assign_role' => route('branch.parties.roles.assign', [$company, $branch, $party]),
                'destroy' => route('branch.parties.destroy', [$company, $branch, $party]),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'party',
                    'parentId' => $party->id,
                ]),
            ],
        ]);
    }

    public function edit(Request $request, Company $company, Branch $branch, Party $party): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $party);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_edit']) {
            abort(403, 'Not authorized to edit parties.');
        }

        $party->load(['roles' => fn ($q) => $q->whereNull('deactivated_at')]);

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'edit',
            'party' => $this->partyDetailPayload($party),
            'roleOptions' => collect(PartyRoleType::cases())->map(fn (PartyRoleType $role): array => [
                'value' => $role->value,
                'label' => str_replace('_', ' ', ucfirst($role->value)),
            ])->values()->all(),
            'links' => [
                'hub' => route('branch.masters.index', [$company, $branch]),
                'index' => route('branch.parties.index', [$company, $branch]),
                'show' => route('branch.parties.show', [$company, $branch, $party]),
                'update' => route('branch.parties.update', [$company, $branch, $party]),
            ],
        ]);
    }

    public function update(Request $request, Company $company, Branch $branch, Party $party): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'is_gst_applicable' => ['sometimes', 'boolean'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->parties->update($actor, $party, $validated, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $updated]);
        }

        return redirect()
            ->route('branch.parties.show', [$company, $branch, $party])
            ->with('success', 'Party updated.');
    }

    public function assignRole(Request $request, Company $company, Branch $branch, Party $party): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'role' => ['required', Rule::enum(PartyRoleType::class)],
            'attributes' => ['nullable', 'array'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $role = $this->parties->assignRole(
                $actor,
                $party,
                PartyRoleType::from($validated['role']),
                $validated['attributes'] ?? [],
                $validated['remark'],
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $role], 201);
        }

        return back()->with('success', 'Role assigned.');
    }

    public function removeRole(
        Request $request,
        Company $company,
        Branch $branch,
        Party $party,
        PartyRole $partyRole,
    ): RedirectResponse|JsonResponse {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $party);

        if ($partyRole->party_id !== $party->id || $partyRole->company_id !== $company->id) {
            abort(404);
        }

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $role = $this->parties->removeRole($actor, $partyRole, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $role]);
        }

        return back()->with('success', 'Role removed.');
    }

    public function destroy(Request $request, Company $company, Branch $branch, Party $party): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->parties->softDelete($actor, $party, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'deleted']);
        }

        return redirect()
            ->route('branch.parties.index', [$company, $branch])
            ->with('success', 'Party deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function partyListPayload(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'contact_number' => $party->contact_number,
            'email' => $party->email,
            'city' => $party->city,
            'state' => $party->state,
            'scope' => $party->branch_id === null ? 'shared' : 'branch',
            'roles' => $party->roles->pluck('role')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partyDetailPayload(Party $party): array
    {
        return [
            'id' => $party->id,
            'name' => $party->name,
            'contact_person' => $party->contact_person,
            'contact_number' => $party->contact_number,
            'email' => $party->email,
            'gstin' => $party->gstin,
            'pan' => $party->pan,
            'is_gst_applicable' => (bool) $party->is_gst_applicable,
            'state' => $party->state,
            'city' => $party->city,
            'address' => $party->address,
            'scope' => $party->branch_id === null ? 'shared' : 'branch',
            'branch_id' => $party->branch_id,
            'roles' => $party->roles->map(fn (PartyRole $role): array => [
                'id' => $role->id,
                'role' => $role->role,
                'attributes' => $role->role_attributes ?? [],
                'deactivated_at' => $role->deactivated_at?->toIso8601String(),
                'is_active' => $role->deactivated_at === null,
            ])->values()->all(),
            'created_at' => $party->created_at?->toIso8601String(),
            'updated_at' => $party->updated_at?->toIso8601String(),
        ];
    }

    private function assertParty(Company $company, Party $party): void
    {
        if ($party->company_id !== $company->id) {
            abort(404);
        }
    }
}
