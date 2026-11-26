<?php

namespace App\Domains\Company;

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Subscription\Lifecycle\SubscriptionStatusEvaluator;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CompanySubscription;
use App\Models\Party;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditEntry;
use App\Support\Workspace\SafeInternalRedirect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Cross-company Owner Panel listings scoped strictly to owned companies
 * (PO-OWNER-GLOBAL-MANAGEMENT).
 */
final class OwnerGlobalPanelService
{
    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly SubscriptionStatusEvaluator $subscriptions = new SubscriptionStatusEvaluator,
    ) {}

    public function assertCustomerOwnerActor(User $user): void
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            abort(403, 'Only active Customer identities may use the Owner Panel.');
        }
    }

    public function assertOwnsAtLeastOneCompany(User $user): void
    {
        $this->assertCustomerOwnerActor($user);

        if (! Company::query()->where('owner_user_id', $user->id)->whereNull('deleted_at')->exists()) {
            abort(403, 'Owner Panel management requires company ownership.');
        }
    }

    /**
     * @return LengthAwarePaginator<int, Company>
     */
    public function paginateOwnedCompanies(
        User $user,
        string $search = '',
        string $status = 'all',
        string $sort = 'name',
        string $direction = 'asc',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->assertCustomerOwnerActor($user);

        $query = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->withCount([
                'branches as branch_count' => fn (Builder $builder) => $builder
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at'),
            ]);

        if ($status === 'active') {
            $query->whereNull('deactivated_at');
        } elseif ($status === 'inactive') {
            $query->whereNotNull('deactivated_at');
        }

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('pan', 'ilike', '%'.$search.'%')
                    ->orWhere('gstin', 'ilike', '%'.$search.'%');
            });
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $sortColumn = match ($sort) {
            'created_at' => 'created_at',
            'status' => 'deactivated_at',
            default => 'name',
        };
        $query->orderBy($sortColumn, $direction)->orderBy('name');

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCompanyRow(Company $company, User $user): array
    {
        $lifecycle = $this->subscriptions->evaluate($company);
        $canAccess = $this->access->canAccessOwnerPanel($user, $company);

        return [
            'id' => $company->id,
            'name' => $company->name,
            'pan' => $company->pan,
            'gstin' => $company->gstin,
            'gst_registration_type' => $company->gst_registration_type?->value,
            'registered_address' => $company->registered_address,
            'contact_person' => $company->contact_person,
            'mobile' => $company->mobile,
            'data_visibility' => $company->data_visibility?->value,
            'branch_count' => (int) ($company->branch_count ?? $company->branches()->whereNull('deactivated_at')->whereNull('deleted_at')->count()),
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
            'subscription_status' => $lifecycle['status']->value,
            'subscription_trial_ends_at' => $lifecycle['trial_ends_at'],
            'created_at' => $company->created_at?->toIso8601String(),
            'can_manage' => $canAccess && $company->isActive(),
            'urls' => [
                'context' => route('workspace.context.company', ['company' => $company]),
                'settings' => SafeInternalRedirect::route('owner.settings'),
                'branches' => SafeInternalRedirect::route('owner.branches'),
                'subscription' => SafeInternalRedirect::route('owner.subscription'),
                'audit' => SafeInternalRedirect::route('owner.audit'),
                'users' => SafeInternalRedirect::route('owner.users'),
                'create_branch' => $company->isActive()
                    ? SafeInternalRedirect::route('owner.companies.branches.create', ['company' => $company])
                    : null,
            ],
        ];
    }

    /**
     * @return array{
     *     owner_name: string,
     *     totals: array{
     *         companies: int,
     *         active_companies: int,
     *         branches: int,
     *         active_subscriptions: int,
     *         trial_or_attention: int
     *     },
     *     companies: list<array<string, mixed>>,
     *     attention: list<array{tone: string, title: string, detail: string, href: string|null}>
     * }
     */
    public function dashboardPayload(User $user): array
    {
        $this->assertCustomerOwnerActor($user);

        $companies = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->withCount([
                'branches as branch_count' => fn (Builder $builder) => $builder
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at'),
            ])
            ->orderBy('name')
            ->get();

        $activeCompanies = $companies->filter(fn (Company $company): bool => $company->isActive())->count();
        $branchTotal = (int) $companies->sum('branch_count');
        $activeSubscriptions = 0;
        $trialOrAttention = 0;
        $attention = [];
        $companyRows = [];

        foreach ($companies as $company) {
            $row = $this->presentCompanyRow($company, $user);
            $companyRows[] = $row;
            $status = $row['subscription_status'];
            if (in_array($status, ['active', 'trial'], true)) {
                $activeSubscriptions++;
            }
            if (in_array($status, ['trial', 'paid_grace', 'restricted', 'none'], true) || ! $company->isActive()) {
                $trialOrAttention++;
                $attention[] = [
                    'tone' => $company->isActive() ? 'warning' : 'info',
                    'title' => $company->name,
                    'detail' => $company->isActive()
                        ? 'Subscription status: '.str_replace('_', ' ', $status)
                        : 'Company is deactivated — Owner admin/billing access only.',
                    'href' => $row['urls']['subscription'],
                ];
            }
        }

        return [
            'owner_name' => $user->name,
            'totals' => [
                'companies' => $companies->count(),
                'active_companies' => $activeCompanies,
                'branches' => $branchTotal,
                'active_subscriptions' => $activeSubscriptions,
                'trial_or_attention' => $trialOrAttention,
            ],
            'companies' => $companyRows,
            'attention' => array_slice($attention, 0, 8),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, CompanyMembership>
     */
    public function paginateOwnedMemberships(
        User $user,
        ?string $companyId = null,
        string $search = '',
        string $status = 'active',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->assertOwnsAtLeastOneCompany($user);

        $ownedCompanyIds = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        $query = CompanyMembership::query()
            ->with([
                'user:id,name,email',
                'company:id,name,owner_user_id,deactivated_at',
                'branchAssignments' => fn ($builder) => $builder
                    ->whereNull('deactivated_at')
                    ->with('branch:id,name'),
            ])
            ->whereIn('company_id', $ownedCompanyIds);

        if ($companyId !== null && $companyId !== '') {
            if (! $ownedCompanyIds->contains($companyId)) {
                abort(403, 'Company filter is outside Owner designation.');
            }
            $query->where('company_id', $companyId);
        }

        if ($status === 'active') {
            $query->whereNull('deactivated_at')->whereNull('deleted_at');
        } elseif ($status === 'inactive') {
            $query->where(function (Builder $builder): void {
                $builder->whereNotNull('deactivated_at')->orWhereNotNull('deleted_at');
            });
        }

        if ($search !== '') {
            $query->whereHas('user', function (Builder $builder) use ($search): void {
                $builder->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('email', 'ilike', '%'.$search.'%');
            });
        }

        return $query->orderByDesc('created_at')->paginate($perPage)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentMembershipRow(CompanyMembership $membership): array
    {
        $company = $membership->company;
        $branchNames = $membership->branchAssignments
            ->map(fn (BranchAssignment $assignment): ?string => $assignment->branch?->name)
            ->filter()
            ->values()
            ->all();

        $roleNames = BranchRoleAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->whereNull('deactivated_at')
            ->with('role:id,name')
            ->get()
            ->map(fn (BranchRoleAssignment $assignment): ?string => $assignment->role?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $membership->id,
            'status' => $membership->isActive() ? 'Active' : 'Inactive',
            'is_owner' => $company !== null && (int) $membership->user_id === (int) $company->owner_user_id,
            'user' => [
                'id' => $membership->user?->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
            ],
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name,
            ],
            'branches' => $branchNames,
            'roles' => $roleNames,
            'urls' => [
                'show' => $company === null
                    ? null
                    : SafeInternalRedirect::route('owner.users.show', ['membership' => $membership]),
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function paginateOwnedAuditEntries(
        User $user,
        ?string $companyId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $this->assertOwnsAtLeastOneCompany($user);

        $ownedCompanyIds = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        $query = AuditEntry::query()
            ->whereIn('company_id', $ownedCompanyIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($companyId !== null && $companyId !== '') {
            if (! $ownedCompanyIds->contains($companyId)) {
                abort(403, 'Company filter is outside Owner designation.');
            }
            $query->where('company_id', $companyId);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return array<string, string>
     */
    public function companyNameMap(Collection $companies): array
    {
        return $companies->mapWithKeys(
            fn (Company $company): array => [$company->id => $company->name],
        )->all();
    }

    /**
     * @return LengthAwarePaginator<int, Branch>
     */
    public function paginateOwnedBranches(
        User $user,
        ?string $companyId = null,
        string $search = '',
        string $status = 'active',
        string $sort = 'name',
        string $direction = 'asc',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $this->assertOwnsAtLeastOneCompany($user);

        $ownedCompanyIds = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        $query = Branch::query()
            ->with(['company:id,name,owner_user_id,deactivated_at'])
            ->whereIn('company_id', $ownedCompanyIds)
            ->withCount([
                'assignments as assigned_users_count' => fn (Builder $builder) => $builder
                    ->whereNull('deactivated_at'),
            ]);

        if ($companyId !== null && $companyId !== '') {
            if (! $ownedCompanyIds->contains($companyId)) {
                abort(403, 'Company filter is outside Owner designation.');
            }
            $query->where('company_id', $companyId);
        }

        if ($status === 'active') {
            $query->whereNull('deactivated_at')->whereNull('deleted_at');
        } elseif ($status === 'inactive') {
            $query->withTrashed()->where(function (Builder $builder): void {
                $builder->whereNotNull('deactivated_at')->orWhereNotNull('deleted_at');
            });
        } elseif ($status === 'all') {
            $query->withTrashed();
        } else {
            $query->whereNull('deactivated_at')->whereNull('deleted_at');
        }

        if ($search !== '') {
            $query->where('name', 'ilike', '%'.$search.'%');
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $sortColumn = match ($sort) {
            'created_at' => 'created_at',
            'company' => 'company_id',
            default => 'name',
        };
        $query->orderBy($sortColumn, $direction)->orderBy('name');

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBranchRow(Branch $branch, User $user): array
    {
        $company = $branch->company;
        $companyActive = $company !== null && $company->isActive();

        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'contact_number' => $branch->contact_number,
            'is_head_office' => (bool) $branch->is_head_office,
            'city' => $branch->city,
            'state' => $branch->state,
            'address' => $branch->address,
            'gst_mode' => $branch->gst_mode->value,
            'gstin' => $branch->gst_mode->value === 'independent'
                ? $branch->gstin
                : $company?->gstin,
            'status' => $branch->isActive() ? 'Active' : 'Deactivated',
            'assigned_users_count' => (int) ($branch->assigned_users_count ?? 0),
            'created_at' => $branch->created_at?->toIso8601String(),
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name,
                'status' => $companyActive ? 'Active' : 'Deactivated',
            ],
            'urls' => [
                'show' => $company === null
                    ? null
                    : SafeInternalRedirect::route('owner.branches.show', ['branch' => $branch]),
                'company_context' => $company === null
                    ? null
                    : route('workspace.context.company', ['company' => $company]),
                'enter_branch_context' => ($company !== null && $companyActive && $branch->isActive())
                    ? route('workspace.context.branch', ['company' => $company, 'branch' => $branch])
                    : null,
                'enter_branch' => ($company !== null && $companyActive && $branch->isActive())
                    ? SafeInternalRedirect::route('branch.dashboard')
                    : null,
                'create_for_company' => ($company !== null && $companyActive)
                    ? SafeInternalRedirect::route('owner.companies.branches.create', ['company' => $company])
                    : null,
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Company>
     */
    public function paginateOwnedSubscriptions(
        User $user,
        string $search = '',
        string $status = 'all',
        int $perPage = 20,
    ): LengthAwarePaginator {
        return $this->paginateOwnedCompanies($user, $search, $status, 'name', 'asc', $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSubscriptionRow(Company $company): array
    {
        $lifecycle = $this->subscriptions->evaluate($company);

        $activeSubscription = CompanySubscription::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->with('items')
            ->orderByDesc('activated_at')
            ->first();

        $activeModules = $activeSubscription === null
            ? []
            : $activeSubscription->items
                ->pluck('module')
                ->filter(fn (mixed $module): bool => is_string($module) && $module !== '')
                ->unique()
                ->values()
                ->all();

        return [
            'id' => $company->id,
            'name' => $company->name,
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
            'subscription_status' => $lifecycle['status']->value,
            'active_modules' => array_values($activeModules),
            'trial_ends_at' => $lifecycle['trial_ends_at'],
            'paid_through_date' => $lifecycle['paid_through_date'],
            'common_renewal_date' => $activeSubscription?->common_renewal_date?->toDateString(),
            'notes' => $lifecycle['notes'],
            'urls' => [
                'context' => route('workspace.context.company', ['company' => $company]),
                'subscription' => SafeInternalRedirect::route('owner.subscription'),
                'company_settings' => SafeInternalRedirect::route('owner.settings'),
            ],
        ];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function ownedCompanyFilterOptions(User $user): array
    {
        $options = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
            ])
            ->values()
            ->all();

        return array_values($options);
    }

    /**
     * Factual compatibility snapshot for Party/Vehicle/Bank scope vs company visibility.
     *
     * @return array{
     *     companies_checked: int,
     *     shared_companies_with_branch_scoped_masters: int,
     *     restricted_companies_with_shared_masters: int,
     *     samples: list<array{company_id: string, company_name: string, issue: string}>
     * }
     */
    public function masterVisibilityCompatibilityReport(): array
    {
        $samples = [];
        $sharedWithBranch = 0;
        $restrictedWithShared = 0;

        $companies = Company::query()->whereNull('deleted_at')->get();
        foreach ($companies as $company) {
            $visibility = $company->data_visibility ?? DataVisibility::SharedData;
            $branchScoped = Party::query()->where('company_id', $company->id)->whereNotNull('branch_id')->exists()
                || Vehicle::query()->where('company_id', $company->id)->whereNotNull('branch_id')->exists()
                || BankCashAccount::query()->where('company_id', $company->id)->whereNotNull('branch_id')->exists();
            $sharedScoped = Party::query()->where('company_id', $company->id)->whereNull('branch_id')->exists()
                || Vehicle::query()->where('company_id', $company->id)->whereNull('branch_id')->exists()
                || BankCashAccount::query()->where('company_id', $company->id)->whereNull('branch_id')->exists();

            if ($visibility === DataVisibility::SharedData && $branchScoped) {
                $sharedWithBranch++;
                if (count($samples) < 20) {
                    $samples[] = [
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'issue' => 'Shared Data company has branch-scoped master records (preserved; new creates are company-wide).',
                    ];
                }
            }

            if ($visibility === DataVisibility::BranchWiseRestricted && $sharedScoped) {
                $restrictedWithShared++;
                if (count($samples) < 20) {
                    $samples[] = [
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'issue' => 'Branch-wise Restricted company has shared (branch_id null) master records (preserved; new creates are branch-scoped).',
                    ];
                }
            }
        }

        return [
            'companies_checked' => $companies->count(),
            'shared_companies_with_branch_scoped_masters' => $sharedWithBranch,
            'restricted_companies_with_shared_masters' => $restrictedWithShared,
            'samples' => $samples,
        ];
    }
}
