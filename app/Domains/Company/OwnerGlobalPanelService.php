<?php

namespace App\Domains\Company;

use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Subscription\Lifecycle\SubscriptionStatusEvaluator;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

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
            'gst_registration_type' => $company->gst_registration_type?->value,
            'data_visibility' => $company->data_visibility?->value,
            'branch_count' => (int) ($company->branch_count ?? $company->branches()->whereNull('deactivated_at')->whereNull('deleted_at')->count()),
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
            'subscription_status' => $lifecycle['status']->value,
            'subscription_trial_ends_at' => $lifecycle['trial_ends_at'],
            'created_at' => $company->created_at?->toIso8601String(),
            'can_manage' => $canAccess && $company->isActive(),
            'urls' => [
                'dashboard' => route('owner.dashboard', ['company' => $company]),
                'settings' => route('owner.settings', ['company' => $company]),
                'branches' => route('owner.branches', ['company' => $company]),
                'subscription' => route('owner.subscription', ['company' => $company]),
            ],
        ];
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
            'status' => $branch->isActive() ? 'Active' : 'Deactivated',
            'assigned_users_count' => (int) ($branch->assigned_users_count ?? 0),
            'created_at' => $branch->created_at?->toIso8601String(),
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name,
                'status' => $companyActive ? 'Active' : 'Deactivated',
            ],
            'urls' => [
                'show' => $company === null ? null : route('owner.branches.show', [
                    'company' => $company,
                    'branch' => $branch,
                ]),
                'enter_branch' => ($company !== null && $companyActive && $branch->isActive())
                    ? route('branch.dashboard', ['company' => $company, 'branch' => $branch])
                    : null,
                'create_for_company' => $company === null ? null : route('owner.branches.create', [
                    'company' => $company,
                ]),
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

        return [
            'id' => $company->id,
            'name' => $company->name,
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
            'subscription_status' => $lifecycle['status']->value,
            'trial_ends_at' => $lifecycle['trial_ends_at'],
            'paid_through_date' => $lifecycle['paid_through_date'],
            'notes' => $lifecycle['notes'],
            'urls' => [
                'subscription' => route('owner.subscription', ['company' => $company]),
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
