<?php

namespace App\Domains\Company;

use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer workspace navigation helpers for P2-WS-8.
 *
 * Authorization always goes through CompanyAccessService. This service lists
 * accessible companies/branches and manages server-side MRU owned-company preference.
 */
final class WorkspaceNavigationService
{
    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    /**
     * @return Collection<int, array{id: string, name: string, status: string, relationship: string, is_owner: bool}>
     */
    public function accessibleCompanies(User $user): Collection
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            return collect();
        }

        $owned = Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company): array => $this->companyPayload($company, true));

        $memberCompanyIds = CompanyMembership::query()
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->pluck('company_id');

        $member = Company::query()
            ->whereIn('id', $memberCompanyIds)
            ->whereNull('deleted_at')
            ->where('owner_user_id', '!=', $user->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Company $company): bool => $this->access->canAccessBranchPanel($user, $company)
                || $this->access->canAccessOwnerPanel($user, $company))
            ->map(fn (Company $company): array => $this->companyPayload($company, false));

        return $owned->concat($member)->values();
    }

    /**
     * @return Collection<int, Company>
     */
    public function ownedCompanies(User $user): Collection
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            return collect();
        }

        return Company::query()
            ->where('owner_user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, array{id: string, name: string, status: string}>
     */
    public function accessibleBranches(User $user, Company $company): Collection
    {
        if (! $this->access->canAccessBranchPanel($user, $company)) {
            return collect();
        }

        if ($this->access->isCompanyOwner($user, $company)) {
            return $company->branches()
                ->whereNull('deactivated_at')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get()
                ->map(fn (Branch $branch): array => $this->branchPayload($branch));
        }

        $membership = $this->access->activeMembership($user, $company);
        if ($membership === null) {
            return collect();
        }

        $branchIds = BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->pluck('branch_id');

        return Branch::query()
            ->whereIn('id', $branchIds)
            ->where('company_id', $company->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => $this->branchPayload($branch));
    }

    public function resolveMruOwnedCompany(User $user): ?Company
    {
        $owned = $this->ownedCompanies($user);
        if ($owned->isEmpty()) {
            return null;
        }

        $mruId = $user->mru_owned_company_id;
        if ($mruId === null) {
            return null;
        }

        $mru = $owned->firstWhere('id', $mruId);
        if ($mru instanceof Company && $this->access->canAccessOwnerPanel($user, $mru)) {
            return $mru;
        }

        $this->clearMruOwnedCompany($user);

        return null;
    }

    public function rememberOwnedCompany(User $user, Company $company): void
    {
        if (! $this->access->isCompanyOwner($user, $company)) {
            return;
        }

        if ($user->mru_owned_company_id === $company->id) {
            return;
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update(['mru_owned_company_id' => $company->id]);

        $user->setAttribute('mru_owned_company_id', $company->id);
    }

    public function clearMruOwnedCompany(User $user): void
    {
        if ($user->mru_owned_company_id === null) {
            return;
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update(['mru_owned_company_id' => null]);

        $user->setAttribute('mru_owned_company_id', null);
    }

    public function hasAnyAccessibleCompany(User $user): bool
    {
        return $this->accessibleCompanies($user)->isNotEmpty();
    }

    /**
     * @return array{id: string, name: string, status: string, relationship: string, is_owner: bool}
     */
    private function companyPayload(Company $company, bool $isOwner): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'status' => $company->isActive() ? 'Active' : 'Deactivated',
            'relationship' => $isOwner ? 'Owner' : 'Member',
            'is_owner' => $isOwner,
        ];
    }

    /**
     * @return array{id: string, name: string, status: string}
     */
    private function branchPayload(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'status' => $branch->isActive() ? 'Active' : 'Deactivated',
        ];
    }
}
