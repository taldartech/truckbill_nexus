<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnerBranchAdministrationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Tax\EffectiveGstConfigurationResolver;
use App\Domains\Tax\GstRateService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Owner Panel branch list, create, detail/edit, deactivate, and Branch Panel entry.
 */
class OwnerBranchesController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly OwnerBranchAdministrationService $branches,
        private readonly EffectiveGstConfigurationResolver $gstResolver = new EffectiveGstConfigurationResolver,
        private readonly GstRateService $gstRates = new GstRateService,
    ) {}

    public function index(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $canManage = $company->isActive() && $scope === CompanyAccessService::OWNER_PANEL_FULL;
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'active');

        $query = Branch::query()
            ->where('company_id', $company->id)
            ->orderBy('name');

        if ($status === 'active') {
            $query->whereNull('deactivated_at')->whereNull('deleted_at');
        } elseif ($status === 'inactive') {
            $query->withTrashed()->where(function ($builder): void {
                $builder->whereNotNull('deactivated_at')->orWhereNotNull('deleted_at');
            });
        } elseif ($status === 'all') {
            $query->withTrashed();
        } else {
            $status = 'active';
            $query->whereNull('deactivated_at')->whereNull('deleted_at');
        }

        if ($search !== '') {
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        $paginator = $query->paginate(20)->withQueryString();
        $capacity = $this->branches->capacitySnapshot($company);

        return Inertia::render('owner/branches/index', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $canManage,
            'canEnterBranch' => $canManage,
            'capacity' => $capacity,
            'filters' => [
                'q' => $search,
                'status' => $status,
            ],
            'branches' => [
                'data' => $paginator->getCollection()->map(function (Branch $branch): array {
                    return [
                        'id' => $branch->id,
                        'name' => $branch->name,
                        'status' => $branch->isActive() ? 'Active' : 'Deactivated',
                        'show_url' => route('owner.branches.show', ['branch' => $branch,
                        ]),
                    ];
                })->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'deactivatedMessage' => $canManage
                ? null
                : 'Branch administration is unavailable while this company is deactivated.',
            'links' => [
                'dashboard' => route('owner.home'),
                'index' => route('owner.branches'),
                'create' => route('owner.branches.create'),
            ],
        ]);
    }

    /**
     * Global Create Branch — no WorkspaceSession required.
     * Optional ?company= preselects an owned company (query key is not company_id).
     */
    public function createGlobal(Request $request): Response|RedirectResponse
    {
        $user = $this->authorizeOwnerIdentity($request);
        $companyOptions = $this->eligibleCompanyOptions($user);

        if ($companyOptions === []) {
            return redirect()
                ->route('owner.companies.create')
                ->with('info', 'Create a company before adding a branch.');
        }

        $requestedId = $request->query('company');
        $requestedId = is_string($requestedId) && $requestedId !== '' ? $requestedId : null;

        $company = null;
        if ($requestedId !== null) {
            $company = Company::query()
                ->whereKey($requestedId)
                ->where('owner_user_id', $user->id)
                ->whereNull('deleted_at')
                ->first();

            if ($company === null) {
                abort(403, 'You do not own the selected company.');
            }
        }

        if ($company === null && count($companyOptions) === 1) {
            $company = Company::query()->whereKey($companyOptions[0]['id'])->first();
        }

        if ($company === null) {
            return Inertia::render('owner/branches/create', [
                'mode' => 'global',
                'company' => null,
                'companyOptions' => $companyOptions,
                'ownerPanelScope' => CompanyAccessService::OWNER_PANEL_FULL,
                'capacity' => null,
                'canCreate' => false,
                'members' => [],
                'customerRoles' => $this->customerRoleOptions(),
                'links' => [
                    'index' => route('owner.branches.all'),
                    'store' => null,
                    'create' => route('owner.branches.create.global'),
                    'subscription' => null,
                ],
            ]);
        }

        return $this->renderCreateForm($request, $company, mode: 'global', companyOptions: $companyOptions);
    }

    /**
     * Company-addressed Create Branch — Company comes from the route, not session.
     */
    public function createForCompany(Request $request, Company $company): Response
    {
        $user = $this->authorizeOwnerIdentity($request);
        if (! $this->access->isCompanyOwner($user, $company) || $company->trashed()) {
            abort(403, 'Owner Panel access denied.');
        }

        return $this->renderCreateForm(
            $request,
            $company,
            mode: 'company',
            companyOptions: $this->eligibleCompanyOptions($user),
            companyLocked: true,
        );
    }

    /**
     * Company-addressed store — explicit route Company is the mutation target.
     */
    public function storeForCompany(Request $request, Company $company): RedirectResponse
    {
        return $this->persistBranch($request, $company);
    }

    /**
     * Legacy session-scoped create (management.company). Redirects to explicit company URL.
     */
    public function create(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        return redirect()->route('owner.companies.branches.create', ['company' => $company]);
    }

    /**
     * Legacy session-scoped store — still authorized against the session company.
     */
    public function store(Request $request, Company $company): RedirectResponse
    {
        return $this->persistBranch($request, $company);
    }

    public function show(Request $request, Branch $branch, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);
        $this->assertBranchBelongsToCompany($company, $branch);

        $canManage = $company->isActive()
            && $scope === CompanyAccessService::OWNER_PANEL_FULL
            && $branch->isActive();

        $activeBranchCount = Branch::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->count();

        $assignments = BranchAssignment::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->with(['membership.user:id,name,email'])
            ->orderBy('created_at')
            ->get();

        $roleAssignments = BranchRoleAssignment::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->with(['role:id,name,slug', 'membership.user:id,name,email'])
            ->orderBy('created_at')
            ->get();

        $audit = AuditEntry::query()
            ->where('company_id', $company->id)
            ->where('subject_type', Branch::class)
            ->where('subject_id', $branch->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'action', 'remark', 'created_at', 'actor_id']);

        return Inertia::render('owner/branches/show', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $canManage,
            'canEnterBranch' => $canManage,
            'canDeactivate' => $canManage && $activeBranchCount > 1,
            'branch' => $this->branchDetailPayload($company, $branch),
            'effective_gst' => $this->gstResolver->resolve($company, $branch)->toArray(),
            'company_gst' => $this->companyGstFormPayload($company),
            'gst_options' => $this->gstFormOptions(),
            'existing_head_office' => $this->existingHeadOfficePayload($company, $branch->id),
            'assignments' => $assignments->map(fn (BranchAssignment $assignment): array => [
                'id' => $assignment->id,
                'user' => [
                    'id' => $assignment->membership?->user?->id,
                    'name' => $assignment->membership?->user?->name,
                    'email' => $assignment->membership?->user?->email,
                ],
            ])->values(),
            'roleAssignments' => $roleAssignments->map(fn (BranchRoleAssignment $assignment): array => [
                'id' => $assignment->id,
                'role' => [
                    'id' => $assignment->role?->id,
                    'name' => $assignment->role?->name,
                    'slug' => $assignment->role?->slug,
                ],
                'user' => [
                    'id' => $assignment->membership?->user?->id,
                    'name' => $assignment->membership?->user?->name,
                    'email' => $assignment->membership?->user?->email,
                ],
            ])->values(),
            'audit' => $audit->map(fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'remark' => $entry->remark,
                'created_at' => $entry->created_at->toIso8601String(),
                'actor_id' => $entry->actor_id,
            ])->values(),
            'links' => [
                'index' => route('owner.branches'),
                'update' => route('owner.branches.update', ['branch' => $branch]),
                'deactivate' => route('owner.branches.deactivate', ['branch' => $branch]),
                'enter' => route('workspace.branches.enter', ['company' => $company, 'branch' => $branch]),
                'users' => route('owner.users'),
            ],
        ]);
    }

    public function update(Request $request, Branch $branch, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertBranchBelongsToCompany($company, $branch);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'remark' => ['required', 'string', 'max:1000'],
            'contact_number' => ['nullable', 'digits:10'],
            'is_head_office' => ['required', 'boolean'],
            'address' => ['nullable', 'string', 'max:2000'],
            'state_id' => ['nullable', 'uuid', Rule::exists('states', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'city_id' => ['nullable', 'uuid', Rule::exists('cities', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'pin' => ['nullable', 'string', 'max:10'],
            'gst_mode' => ['required', Rule::in(['inherit_company', 'independent'])],
            'gst_registration_type' => ['nullable', 'string', 'max:32'],
            'tax_liability' => ['nullable', 'string', 'max:16'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'gst_rate_id' => ['nullable', 'uuid', 'exists:gst_rates,id'],
        ]);

        if ($validated['gst_mode'] === 'independent') {
            $request->validate([
                'gst_registration_type' => ['required', 'string', 'max:32'],
                'tax_liability' => ['required', 'string', 'max:16'],
            ]);
        }

        $attributes = [
            'contact_number' => $validated['contact_number'] ?? null,
            'is_head_office' => (bool) $validated['is_head_office'],
            'address' => $validated['address'] ?? null,
            'state_id' => $validated['state_id'] ?? null,
            'city_id' => $validated['city_id'] ?? null,
            'pin' => $validated['pin'] ?? null,
            'gst_mode' => $validated['gst_mode'],
            'gst_registration_type' => isset($validated['gst_registration_type'])
                ? GstRegistrationType::from((string) $validated['gst_registration_type'])
                : null,
            'tax_liability' => isset($validated['tax_liability'])
                ? TaxLiability::from((string) $validated['tax_liability'])
                : null,
            'gstin' => $validated['gstin'] ?? null,
            'gst_rate_id' => $validated['gst_rate_id'] ?? null,
        ];

        try {
            $this->branches->updateBranch($user, $branch, $validated['name'], $validated['remark'], $attributes);
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.branches.show', ['branch' => $branch])
            ->with('success', 'Branch updated.');
    }

    public function deactivate(Request $request, Branch $branch, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertBranchBelongsToCompany($company, $branch);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->branches->deactivateBranch($user, $branch, $validated['remark']);
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.branches')
            ->with('success', 'Branch deactivated.');
    }

    private function assertBranchBelongsToCompany(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }
    }

    private function persistBranch(Request $request, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'remark' => ['required', 'string', 'max:1000'],
            'contact_number' => ['nullable', 'digits:10'],
            'is_head_office' => ['required', 'boolean'],
            'address' => ['nullable', 'string', 'max:2000'],
            'state_id' => ['nullable', 'uuid', Rule::exists('states', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'city_id' => ['nullable', 'uuid', Rule::exists('cities', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'pin' => ['nullable', 'string', 'max:10'],
            'gst_mode' => ['required', Rule::in(['inherit_company', 'independent'])],
            'gst_registration_type' => ['nullable', 'string', 'max:32'],
            'tax_liability' => ['nullable', 'string', 'max:16'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'gst_rate_id' => ['nullable', 'uuid', 'exists:gst_rates,id'],
            'assignments' => ['nullable', 'array', 'max:50'],
            'assignments.*.user_id' => [
                'required',
                'integer',
                Rule::exists('company_memberships', 'user_id')->where(function ($query) use ($company): void {
                    $query->where('company_id', $company->id)
                        ->whereNull('deactivated_at')
                        ->whereNull('deleted_at');
                }),
            ],
            'assignments.*.role_id' => [
                'required',
                'uuid',
                Rule::exists('roles', 'id')->where(function ($query): void {
                    $query->where('domain', PermissionDomain::Customer->value)
                        ->whereNull('deactivated_at')
                        ->whereNull('deleted_at');
                }),
            ],
        ]);

        if ($validated['gst_mode'] === 'independent') {
            $request->validate([
                'gst_registration_type' => ['required', 'string', 'max:32'],
                'tax_liability' => ['required', 'string', 'max:16'],
            ]);
        }

        /** @var list<array{user_id: int|string, role_id: string}> $assignments */
        $assignments = [];
        $seenUsers = [];
        foreach ($validated['assignments'] ?? [] as $row) {
            if (! is_array($row) || ! isset($row['user_id'], $row['role_id'])) {
                continue;
            }
            $userKey = (string) $row['user_id'];
            if (isset($seenUsers[$userKey])) {
                continue;
            }
            $seenUsers[$userKey] = true;
            $assignments[] = [
                'user_id' => $row['user_id'],
                'role_id' => (string) $row['role_id'],
            ];
        }

        $attributes = [
            'contact_number' => $validated['contact_number'] ?? null,
            'is_head_office' => (bool) $validated['is_head_office'],
            'address' => $validated['address'] ?? null,
            'state_id' => $validated['state_id'] ?? null,
            'city_id' => $validated['city_id'] ?? null,
            'pin' => $validated['pin'] ?? null,
            'gst_mode' => $validated['gst_mode'],
            'gst_registration_type' => isset($validated['gst_registration_type'])
                ? GstRegistrationType::from((string) $validated['gst_registration_type'])
                : null,
            'tax_liability' => isset($validated['tax_liability'])
                ? TaxLiability::from((string) $validated['tax_liability'])
                : null,
            'gstin' => $validated['gstin'] ?? null,
            'gst_rate_id' => $validated['gst_rate_id'] ?? null,
        ];

        try {
            $branch = $this->branches->createBranch(
                $user,
                $company,
                $validated['name'],
                $validated['remark'],
                $assignments,
                $attributes,
            );
        } catch (CompanyDomainException|RbacDomainException|RbacAuthorizationException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.branches.all')
            ->with('success', 'Branch created for '.$company->name.'.');
    }

    /**
     * @param  list<array{id: string, name: string, status: string, available_branch_slots: int}>  $companyOptions
     */
    private function renderCreateForm(
        Request $request,
        Company $company,
        string $mode,
        array $companyOptions,
        bool $companyLocked = false,
    ): Response {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $capacity = $this->branches->capacitySnapshot($company);

        $members = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->where('user_id', '!=', $company->owner_user_id)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        $existingHeadOffice = Branch::query()
            ->where('company_id', $company->id)
            ->where('is_head_office', true)
            ->whereNull('deleted_at')
            ->first(['id', 'name']);

        $companyGst = $this->gstResolver->companyGstPayload($company);

        return Inertia::render('owner/branches/create', [
            'mode' => $mode,
            'company' => $this->companyPayload($company),
            'companyLocked' => $companyLocked || $mode === 'company',
            'companyOptions' => $companyOptions,
            'ownerPanelScope' => $scope,
            'capacity' => $capacity,
            'canCreate' => $capacity['available_branch_slots'] >= 1,
            'existingHeadOffice' => $existingHeadOffice === null ? null : [
                'id' => $existingHeadOffice->id,
                'name' => $existingHeadOffice->name,
            ],
            'companyGst' => [
                'gst_registration_type' => $companyGst['registration_type']?->value,
                'tax_liability' => $companyGst['tax_liability']?->value,
                'gstin' => $companyGst['gstin'],
                'gst_rate_id' => $companyGst['gst_rate_id'],
                'gst_rate_snapshot' => $companyGst['gst_rate_snapshot'],
            ],
            'gstOptions' => [
                'registration_types' => array_map(
                    fn (GstRegistrationType $case): array => ['value' => $case->value, 'label' => $case->name],
                    GstRegistrationType::cases(),
                ),
                'tax_liabilities' => array_map(
                    fn (TaxLiability $case): array => ['value' => $case->value, 'label' => strtoupper($case->value)],
                    TaxLiability::cases(),
                ),
                'gst_rates' => $this->gstRates->listActive()->map(fn ($rate): array => [
                    'id' => $rate->id,
                    'label' => $rate->displayLabel(),
                    'rate_percent' => (string) $rate->rate_percent,
                ])->values(),
            ],
            'members' => $members->map(fn (CompanyMembership $membership): array => [
                'user_id' => $membership->user_id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ])->values(),
            'customerRoles' => $this->customerRoleOptions(),
            'links' => [
                'index' => route('owner.branches.all'),
                'store' => route('owner.companies.branches.store', ['company' => $company]),
                'create' => route('owner.branches.create.global'),
                // Context POST only for operational entry — capacity CTA uses enter then subscription.
                'subscription' => route('workspace.context.company', [
                    'company' => $company,
                ]),
                'subscription_redirect' => route('owner.subscription', absolute: false),
            ],
        ]);
    }

    /**
     * @return list<array{id: string, name: string, status: string, available_branch_slots: int}>
     */
    private function eligibleCompanyOptions(User $user): array
    {
        return Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->whereNull('deactivated_at')
            ->orderBy('name')
            ->get()
            ->map(function (Company $company): array {
                $capacity = $this->branches->capacitySnapshot($company);

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'status' => 'Active',
                    'available_branch_slots' => (int) $capacity['available_branch_slots'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    private function customerRoleOptions(): array
    {
        return Role::query()
            ->where('domain', PermissionDomain::Customer->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function branchDetailPayload(Company $company, Branch $branch): array
    {
        $effective = $this->gstResolver->resolve($company, $branch);

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'contact_number' => $branch->contact_number,
            'is_head_office' => (bool) $branch->is_head_office,
            'address' => $branch->address,
            'state' => $branch->state,
            'state_id' => $branch->state_id,
            'city' => $branch->city,
            'city_id' => $branch->city_id,
            'pin' => $branch->pin,
            'gst_mode' => $branch->gst_mode?->value ?? 'inherit_company',
            'gst_registration_type' => $branch->gst_registration_type?->value,
            'tax_liability' => $branch->tax_liability?->value,
            'gstin' => $branch->gstin,
            'gst_rate_id' => $branch->gst_rate_id,
            'effective_gstin' => $effective->gstin,
            'status' => $branch->isActive() ? 'Active' : 'Deactivated',
            'created_at' => $branch->created_at?->toIso8601String(),
            'updated_at' => $branch->updated_at?->toIso8601String(),
            'deactivated_at' => $branch->deactivated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyGstFormPayload(Company $company): array
    {
        $companyGst = $this->gstResolver->companyGstPayload($company);

        return [
            'gst_registration_type' => $companyGst['registration_type']?->value,
            'tax_liability' => $companyGst['tax_liability']?->value,
            'gstin' => $companyGst['gstin'],
            'gst_rate_id' => $companyGst['gst_rate_id'],
            'gst_rate_snapshot' => $companyGst['gst_rate_snapshot'],
        ];
    }

    /**
     * @return array{registration_types: list<array{value: string, label: string}>, tax_liabilities: list<array{value: string, label: string}>, gst_rates: Collection<int, array{id: string, label: string, rate_percent: string}>}
     */
    private function gstFormOptions(): array
    {
        return [
            'registration_types' => array_map(
                fn (GstRegistrationType $case): array => ['value' => $case->value, 'label' => $case->name],
                GstRegistrationType::cases(),
            ),
            'tax_liabilities' => array_map(
                fn (TaxLiability $case): array => ['value' => $case->value, 'label' => strtoupper($case->value)],
                TaxLiability::cases(),
            ),
            'gst_rates' => $this->gstRates->listActive()->map(fn ($rate): array => [
                'id' => $rate->id,
                'label' => $rate->displayLabel(),
                'rate_percent' => (string) $rate->rate_percent,
            ])->values(),
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function existingHeadOfficePayload(Company $company, ?string $exceptBranchId = null): ?array
    {
        $query = Branch::query()
            ->where('company_id', $company->id)
            ->where('is_head_office', true)
            ->whereNull('deleted_at');

        if ($exceptBranchId !== null) {
            $query->whereKeyNot($exceptBranchId);
        }

        $existing = $query->first(['id', 'name']);

        return $existing === null ? null : [
            'id' => $existing->id,
            'name' => $existing->name,
        ];
    }
}
