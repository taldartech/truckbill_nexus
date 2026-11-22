<?php

namespace App\Domains\Master\Support;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

/**
 * Shared company/branch permission gates for Phase 3 master services.
 * Mirrors PartyService authorization semantics.
 */
final class MasterAccess
{
    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    public function assertCanView(User $actor, Company $company, Branch $branch): void
    {
        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_VIEW, $company, $branch)) {
            throw new MasterDomainException('Not authorized to view master data.');
        }
    }

    public function assertCanMutate(User $actor, Company $company, ?Branch $branch): void
    {
        if ($branch === null) {
            if ($this->access->isCompanyOwner($actor, $company) && $company->isActive()) {
                return;
            }

            $membership = $this->access->activeMembership($actor, $company);
            if ($membership === null || ! $company->isActive()) {
                throw new MasterDomainException('Not authorized to manage master data.');
            }

            $anyBranch = $company->branches()->whereNull('deactivated_at')->get()
                ->first(fn (Branch $b) => $this->authorizer->allowsCustomer(
                    $actor,
                    PermissionCodes::PLATFORM_CREATE,
                    $company,
                    $b,
                ));

            if ($anyBranch === null) {
                throw new MasterDomainException('Not authorized to manage master data.');
            }

            return;
        }

        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_CREATE, $company, $branch)
            && ! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch)) {
            throw new MasterDomainException('Not authorized to manage master data.');
        }
    }

    public function assertCanEdit(User $actor, Company $company, ?Branch $branch): void
    {
        if ($branch === null) {
            if ($this->access->isCompanyOwner($actor, $company) && $company->isActive()) {
                return;
            }

            throw new MasterDomainException('Not authorized to edit master data.');
        }

        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_EDIT, $company, $branch)) {
            throw new MasterDomainException('Not authorized to edit master data.');
        }
    }

    public function assertCanDelete(User $actor, Company $company, ?Branch $branch): void
    {
        if ($branch === null) {
            if ($this->access->isCompanyOwner($actor, $company) && $company->isActive()) {
                return;
            }

            throw new MasterDomainException('Not authorized to delete master data.');
        }

        $this->assertCompanyBranchAccess($actor, $company, $branch);

        if ($this->access->isCompanyOwner($actor, $company)) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::PLATFORM_DELETE, $company, $branch)) {
            throw new MasterDomainException('Not authorized to delete master data.');
        }
    }

    public function assertOwnsCompany(User $actor, Company $company): void
    {
        if (! $company->isOwnedBy($actor) || ! $company->isActive()) {
            throw new MasterDomainException('Only the company owner may manage owner-scoped masters.');
        }
    }

    public function resolveActorBranch(User $actor, Company $company): Branch
    {
        if ($this->access->isCompanyOwner($actor, $company)) {
            $branch = $company->branches()->whereNull('deactivated_at')->orderBy('name')->first();
        } else {
            $branch = $company->branches()->whereNull('deactivated_at')->get()
                ->first(fn (Branch $b) => $this->access->canAccessBranch($actor, $company, $b));
        }

        if ($branch === null) {
            throw new MasterDomainException('No accessible branch for actor.');
        }

        return $branch;
    }

    public function assertCompanyBranchAccess(User $actor, Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new MasterDomainException('Branch does not belong to company.');
        }

        if (! $company->isActive()) {
            throw new MasterDomainException('Company is not active.');
        }

        if (! $this->access->isCompanyOwner($actor, $company)
            && $this->access->activeMembership($actor, $company) === null) {
            throw new MasterDomainException('Company access denied.');
        }

        if (! $this->access->canAccessBranch($actor, $company, $branch)) {
            throw new MasterDomainException('Branch access denied.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withTenant(User $actor, Company $company, ?Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(
            companyId: $company->id,
            branchId: $branch?->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: $actor->isInternal(),
            isOwnerScope: $this->access->isCompanyOwner($actor, $company),
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
