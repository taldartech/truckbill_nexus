<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Invitation\Enums\InvitationStatus;
use App\Domains\Invitation\Exceptions\InvitationException;
use App\Domains\Invitation\InvitationService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\RoleAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Invitation;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Owner users, memberships, invitations, and branch role assignment UI.
 */
class OwnerUsersController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly CompanyWorkspaceService $workspace,
        private readonly InvitationService $invitations,
        private readonly RoleAssignmentService $roles,
    ) {}

    public function index(Request $request, Company $company): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'active');

        $membershipQuery = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->with(['user'])
            ->orderByDesc('created_at');

        if ($status === 'active') {
            $membershipQuery->whereNull('deactivated_at');
        } elseif ($status === 'inactive') {
            $membershipQuery->withTrashed()->where(function ($query): void {
                $query->whereNotNull('deactivated_at')->orWhereNotNull('deleted_at');
            });
        } elseif ($status === 'all') {
            $membershipQuery->withTrashed();
        }

        if ($search !== '') {
            $membershipQuery->whereHas('user', function ($query) use ($search): void {
                $query->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('email', 'ilike', '%'.$search.'%');
            });
        }

        $memberships = $membershipQuery->paginate(20)->withQueryString();

        $pendingInvitations = Invitation::query()
            ->where('company_id', $company->id)
            ->where('status', InvitationStatus::Pending)
            ->with('branch:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $branches = Branch::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('owner/users/index', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $scope === CompanyAccessService::OWNER_PANEL_FULL,
            'filters' => [
                'q' => $search,
                'status' => in_array($status, ['active', 'inactive', 'all'], true) ? $status : 'active',
            ],
            'memberships' => [
                'data' => $memberships->getCollection()->map(function (CompanyMembership $membership) use ($company): array {
                    return [
                        'id' => $membership->id,
                        'status' => $membership->isActive() ? 'Active' : 'Inactive',
                        'is_owner' => (int) $membership->user_id === (int) $company->owner_user_id,
                        'user' => [
                            'id' => $membership->user?->id,
                            'name' => $membership->user?->name,
                            'email' => $membership->user?->email,
                        ],
                        'show_url' => route('owner.users.show', [
                            'company' => $company,
                            'membership' => $membership,
                        ]),
                    ];
                })->values(),
                'links' => $memberships->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $memberships->currentPage(),
                    'last_page' => $memberships->lastPage(),
                    'total' => $memberships->total(),
                ],
            ],
            'pendingInvitations' => $pendingInvitations->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'branch_name' => $invitation->branch?->name,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ])->values(),
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'links' => [
                'dashboard' => route('owner.dashboard', ['company' => $company]),
                'invite' => route('owner.users.invite', ['company' => $company]),
                'index' => route('owner.users', ['company' => $company]),
            ],
        ]);
    }

    public function show(Request $request, Company $company, CompanyMembership $membership): Response
    {
        ['scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);
        $this->assertMembershipBelongsToCompany($company, $membership);

        $membership->load([
            'user',
            'branchAssignments.branch',
        ]);

        $roleAssignments = BranchRoleAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->whereNull('deleted_at')
            ->with(['role:id,name,slug', 'branch:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $customerRoles = Role::query()
            ->where('domain', PermissionDomain::Customer)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $activeBranches = Branch::query()
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name']);

        $assignedBranchIds = $membership->branchAssignments
            ->filter(fn (BranchAssignment $assignment): bool => $assignment->isActive())
            ->pluck('branch_id')
            ->all();

        return Inertia::render('owner/users/show', [
            'company' => $this->companyPayload($company),
            'ownerPanelScope' => $scope,
            'canManage' => $scope === CompanyAccessService::OWNER_PANEL_FULL,
            'membership' => [
                'id' => $membership->id,
                'status' => $membership->isActive() ? 'Active' : 'Inactive',
                'is_owner' => (int) $membership->user_id === (int) $company->owner_user_id,
                'user' => [
                    'id' => $membership->user?->id,
                    'name' => $membership->user?->name,
                    'email' => $membership->user?->email,
                ],
                'branch_assignments' => $membership->branchAssignments->map(function (BranchAssignment $assignment): array {
                    return [
                        'id' => $assignment->id,
                        'status' => $assignment->isActive() ? 'Active' : 'Inactive',
                        'branch' => [
                            'id' => $assignment->branch?->id,
                            'name' => $assignment->branch?->name,
                        ],
                    ];
                })->values(),
                'role_assignments' => $roleAssignments->map(function (BranchRoleAssignment $assignment): array {
                    return [
                        'id' => $assignment->id,
                        'status' => $assignment->isActive() ? 'Active' : 'Inactive',
                        'role' => [
                            'id' => $assignment->role?->id,
                            'name' => $assignment->role?->name,
                            'slug' => $assignment->role?->slug,
                        ],
                        'branch' => [
                            'id' => $assignment->branch?->id,
                            'name' => $assignment->branch?->name,
                        ],
                    ];
                })->values(),
            ],
            'assignableBranches' => $activeBranches
                ->reject(fn (Branch $branch): bool => in_array($branch->id, $assignedBranchIds, true))
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ])
                ->values(),
            'customerRoles' => $customerRoles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->values(),
            'links' => [
                'index' => route('owner.users', ['company' => $company]),
                'dashboard' => route('owner.dashboard', ['company' => $company]),
                'deactivate' => route('owner.users.deactivate', [
                    'company' => $company,
                    'membership' => $membership,
                ]),
            ],
        ]);
    }

    public function invite(Request $request, Company $company): RedirectResponse
    {
        return $this->storeInvitation($request, $company, null);
    }

    public function inviteForBranch(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        return $this->storeInvitation($request, $company, $branch);
    }

    public function revokeInvitation(Request $request, Company $company, Invitation $invitation): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        if ($invitation->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->invitations->revoke($user, $invitation, $validated['remark']);
        } catch (InvitationException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.users', ['company' => $company])
            ->with('success', 'Invitation revoked.');
    }

    public function deactivateMembership(Request $request, Company $company, CompanyMembership $membership): RedirectResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertMembershipBelongsToCompany($company, $membership);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->workspace->deactivateMembership($membership, $validated['remark']);
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.users', ['company' => $company])
            ->with('success', 'Membership deactivated.');
    }

    public function assignBranch(
        Request $request,
        Company $company,
        CompanyMembership $membership,
        Branch $branch,
    ): RedirectResponse {
        $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertMembershipBelongsToCompany($company, $membership);

        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->workspace->assignBranch($membership, $branch, $validated['remark']);
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.users.show', ['company' => $company, 'membership' => $membership])
            ->with('success', 'Branch access assigned.');
    }

    public function assignRole(
        Request $request,
        Company $company,
        CompanyMembership $membership,
        Branch $branch,
    ): RedirectResponse {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);
        $this->assertMembershipBelongsToCompany($company, $membership);

        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'role_id' => ['required', 'uuid', 'exists:roles,id'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $branchAssignment = BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->first();

        if ($branchAssignment === null) {
            throw ValidationException::withMessages([
                'role_id' => 'An active branch assignment is required before assigning a role.',
            ]);
        }

        $role = Role::query()->findOrFail($validated['role_id']);

        try {
            $this->roles->assignCustomerBranchRole(
                $user,
                $membership,
                $branch,
                $branchAssignment,
                $role,
                $validated['remark'],
            );
        } catch (RbacDomainException|RbacAuthorizationException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['role_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.users.show', ['company' => $company, 'membership' => $membership])
            ->with('success', 'Branch role assigned.');
    }

    private function storeInvitation(Request $request, Company $company, ?Branch $branch): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $this->invitations->create($user, $company, $validated['email'], $branch);
        } catch (InvitationException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.users', ['company' => $company])
            ->with('success', 'Invitation created.');
    }

    public function revokeRole(Request $request, Company $company, BranchRoleAssignment $roleAssignment): RedirectResponse
    {
        ['user' => $user] = $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        if ($roleAssignment->company_id !== $company->id) {
            abort(404);
        }

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->roles->revokeCustomerBranchRole($user, $roleAssignment, $validated['remark']);
        } catch (RbacDomainException|RbacAuthorizationException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.users.show', [
                'company' => $company,
                'membership' => $roleAssignment->company_membership_id,
            ])
            ->with('success', 'Branch role revoked.');
    }

    private function assertMembershipBelongsToCompany(Company $company, CompanyMembership $membership): void
    {
        if ($membership->company_id !== $company->id) {
            abort(404);
        }
    }
}
