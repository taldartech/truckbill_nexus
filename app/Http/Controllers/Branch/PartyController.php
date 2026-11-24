<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyProfileValidator;
use App\Domains\Company\MasterDataVisibilityResolver;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Master\Party\PartyRoleAttributeValidator;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Support\MasterAccess;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\TransporterTdsCalculationService;
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
        private readonly TransporterTdsCalculationService $transporterTds,
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
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'create' => route('branch.parties.create', absolute: false),
                'store' => route('branch.parties.store', absolute: false),
            ]),
        ]);
    }

    /**
     * Bounded, role-filtered Party options for operational selectors (JSON).
     */
    public function options(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(PartyRoleType::class)],
            'roles' => ['sometimes', 'array', 'max:8'],
            'roles.*' => [Rule::enum(PartyRoleType::class)],
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'selected_id' => ['nullable', 'uuid'],
        ]);

        $roleInputs = $validated['roles'] ?? [$validated['role']];
        $roles = [];
        foreach ($roleInputs as $roleInput) {
            $roles[] = $roleInput instanceof PartyRoleType
                ? $roleInput
                : PartyRoleType::from((string) $roleInput);
        }

        $options = app(PartyOptionQuery::class)->forRoles(
            company: $company,
            branch: $branch,
            roles: $roles,
            limit: (int) ($validated['limit'] ?? 50),
            query: $validated['q'] ?? null,
            selectedId: $validated['selected_id'] ?? null,
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
            abort(403, 'Not authorized to create parties.');
        }

        return Inertia::render('branch/masters/parties/form', [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'permissions' => $permissions,
            'mode' => 'create',
            'party' => null,
            'dataVisibility' => $this->dataVisibilityPresentation($company),
            'roleOptions' => collect(PartyRoleType::cases())->map(fn (PartyRoleType $role): array => [
                'value' => $role->value,
                'label' => str_replace('_', ' ', ucfirst($role->value)),
            ])->values()->all(),
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'store' => route('branch.parties.store', absolute: false),
            ]),
        ]);
    }

    public function previewTds(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $permissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        if (! $permissions['can_create'] && ! $permissions['can_edit']) {
            abort(403, 'Not authorized to preview transporter TDS.');
        }

        $validated = $request->validate([
            'pan' => ['nullable', 'string', 'max:20'],
            'pan_status' => ['nullable', 'string', 'max:32'],
            'declaration' => ['nullable'],
            'declaration_type' => ['nullable', 'string', 'max:16'],
            'goods_vehicles_count' => ['nullable'],
            'tds_percentage' => ['nullable'],
            'tds_override' => ['nullable'],
            'tds_adjustment_remark' => ['nullable', 'string', 'max:1000'],
            'party' => ['nullable', 'array'],
            'party.pan' => ['nullable', 'string', 'max:20'],
            'attributes' => ['nullable', 'array'],
        ]);

        $previewKeys = [
            'pan',
            'pan_status',
            'declaration',
            'declaration_type',
            'goods_vehicles_count',
            'tds_percentage',
            'tds_override',
            'tds_adjustment_remark',
        ];
        $fromRequest = array_intersect_key($validated, array_flip($previewKeys));

        $roleAttrs = $this->normalizeIncomingRoleAttributes(array_merge(
            $validated['attributes'] ?? [],
            $fromRequest,
        ));

        $partyAttrs = array_merge(
            $validated['party'] ?? [],
            filled($validated['pan'] ?? null) ? ['pan' => $validated['pan']] : [],
        );

        $pan = strtoupper(trim((string) ($roleAttrs['pan'] ?? $partyAttrs['pan'] ?? '')));
        if ($pan !== '' && ! preg_match(CompanyProfileValidator::PAN_PATTERN, $pan)) {
            throw ValidationException::withMessages(['pan' => 'PAN format is invalid (AAAAA9999A).']);
        }

        if (isset($roleAttrs['pan_status']) && filled($roleAttrs['pan_status'])) {
            $status = strtolower(trim((string) $roleAttrs['pan_status']));
            if (! in_array($status, PartyRoleAttributeValidator::PAN_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'pan_status' => 'pan_status must be one of: '.implode(', ', PartyRoleAttributeValidator::PAN_STATUSES).'.',
                ]);
            }
            $roleAttrs['pan_status'] = $status;
        }

        if (isset($roleAttrs['declaration_type']) && filled($roleAttrs['declaration_type'])) {
            $type = strtolower(trim((string) $roleAttrs['declaration_type']));
            if (! in_array($type, ['owner', 'agency'], true)) {
                throw ValidationException::withMessages([
                    'declaration_type' => 'Declaration type must be owner or agency.',
                ]);
            }
            $roleAttrs['declaration_type'] = $type;
        }

        $context = $this->transporterTds->buildContextFromTransporter($partyAttrs, $roleAttrs);
        $override = filter_var($roleAttrs['tds_override'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) ($roleAttrs['tds_override'] ?? false);

        try {
            $result = $this->transporterTds->calculate(
                $context,
                overrideRate: $override && isset($roleAttrs['tds_percentage'])
                    ? (string) $roleAttrs['tds_percentage']
                    : null,
                overrideRemark: $override ? ($roleAttrs['tds_adjustment_remark'] ?? null) : null,
                allowOverride: $override,
            );
        } catch (TaxDomainException $e) {
            throw ValidationException::withMessages([
                'tds' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
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

        $roles = $this->normalizeIncomingRoles($validated['roles']);
        $attributes = $this->normalizePartyAttributes($validated);

        // PO-MASTER-COMPANY-VISIBILITY: ignore any client-submitted scope.
        $scopeBranch = app(MasterDataVisibilityResolver::class)
            ->scopeBranchForCreate($company, $branch);

        try {
            $party = $this->parties->create(
                actor: $actor,
                company: $company,
                branch: $scopeBranch,
                attributes: $attributes,
                roles: $roles,
                remark: $validated['remark'] ?? 'Create party',
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $party], 201);
        }

        return redirect()
            ->route('branch.parties.show', [$party])
            ->with('success', 'Party created.');
    }

    public function show(Request $request, Party $party, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);
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
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'edit' => route('branch.parties.edit', [$party], absolute: false),
                'update' => route('branch.parties.update', [$party], absolute: false),
                'assign_role' => route('branch.parties.roles.assign', [$party], absolute: false),
                'destroy' => route('branch.parties.destroy', [$party], absolute: false),
                'documents' => route('branch.documents.index', [
                    'company' => $company,
                    'branch' => $branch,
                    'parentType' => 'party',
                    'parentId' => $party->id,
                ], absolute: false),
            ]),
        ]);
    }

    public function edit(Request $request, Party $party, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);
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
            'links' => array_merge($this->branchMasterNavLinks(), [
                'index' => route('branch.parties.index', absolute: false),
                'show' => route('branch.parties.show', [$party], absolute: false),
                'update' => route('branch.parties.update', [$party], absolute: false),
            ]),
        ]);
    }

    public function update(Request $request, Party $party, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);

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
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*.role' => ['required_with:roles', Rule::enum(PartyRoleType::class)],
            'roles.*.attributes' => ['nullable', 'array'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $roles = array_key_exists('roles', $validated)
            ? $this->normalizeIncomingRoles($validated['roles'])
            : null;
        $attributes = $this->normalizePartyAttributes($validated);

        try {
            $updated = $this->parties->update(
                $actor,
                $party,
                $attributes,
                $validated['remark'],
                $roles,
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => $updated]);
        }

        return redirect()
            ->route('branch.parties.show', [$party])
            ->with('success', 'Party updated.');
    }

    public function assignRole(Request $request, Party $party, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);

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

    public function removeRole(Request $request, Party $party, PartyRole $partyRole, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);

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

    public function destroy(Request $request, Party $party, Company $company, Branch $branch): RedirectResponse|JsonResponse
    {
        $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertParty($company, $branch, $party);

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
            ->route('branch.parties.index')
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

    private function assertParty(Company $company, Branch $branch, Party $party): void
    {
        if ($party->company_id !== $company->id) {
            abort(404);
        }

        try {
            app(MasterAccess::class)
                ->assertRecordVisibleInBranch($company, $branch, Party::class, $party->id);
        } catch (MasterDomainException) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePartyAttributes(array $validated): array
    {
        $attributes = collect($validated)->except(['roles', 'remark'])->all();

        if (array_key_exists('is_gst_applicable', $attributes)) {
            $attributes['is_gst_applicable'] = filter_var(
                $attributes['is_gst_applicable'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            ) ?? (bool) $attributes['is_gst_applicable'];
        }

        return $attributes;
    }

    /**
     * @param  list<array{role: string|\BackedEnum, attributes?: array<string, mixed>|null}>  $roles
     * @return list<array{role: string, attributes: array<string, mixed>}>
     */
    private function normalizeIncomingRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $value = $role['role'];
            $roleValue = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
            $normalized[] = [
                'role' => $roleValue,
                'attributes' => $this->normalizeIncomingRoleAttributes($role['attributes'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * Coerce checkbox / string boolean fields from HTML forms.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeIncomingRoleAttributes(array $attributes): array
    {
        foreach (['declaration', 'tds_override'] as $boolKey) {
            if (! array_key_exists($boolKey, $attributes)) {
                continue;
            }

            $raw = $attributes[$boolKey];
            if (is_bool($raw)) {
                continue;
            }

            if (is_string($raw)) {
                $lower = strtolower(trim($raw));
                if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                    $attributes[$boolKey] = true;
                } elseif (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                    $attributes[$boolKey] = false;
                }
            }
        }

        return $attributes;
    }
}
