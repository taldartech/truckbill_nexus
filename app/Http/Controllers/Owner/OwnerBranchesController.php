<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnerBranchAdministrationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Role;
use App\Support\Audit\AuditEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function create(Request $request, Company $company): Response
    {
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

        $roles = Role::query()
            ->where('domain', PermissionDomain::Customer->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return Inertia::render('owner/branches/create', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'capacity' => $capacity,
            'canCreate' => $capacity['available_branch_slots'] >= 1,
            'members' => $members->map(fn (CompanyMembership $membership): array => [
                'user_id' => $membership->user_id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ])->values(),
            'customerRoles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->values(),
            'links' => [
                'index' => route('owner.branches'),
                'store' => route('owner.branches.store'),
            ],
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'remark' => ['required', 'string', 'max:1000'],
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

        try {
            $branch = $this->branches->createBranch(
                $user,
                $company,
                $validated['name'],
                $validated['remark'],
                $assignments,
            );
        } catch (CompanyDomainException|RbacDomainException|RbacAuthorizationException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.branches.show', ['branch' => $branch])
            ->with('success', 'Branch created.');
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
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'status' => $branch->isActive() ? 'Active' : 'Deactivated',
                'created_at' => $branch->created_at?->toIso8601String(),
                'updated_at' => $branch->updated_at?->toIso8601String(),
                'deactivated_at' => $branch->deactivated_at?->toIso8601String(),
            ],
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
        ]);

        try {
            $this->branches->updateBranch($user, $branch, $validated['name'], $validated['remark']);
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
}
