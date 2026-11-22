<?php

namespace App\Domains\Company;

use App\Domains\Company\Exceptions\CompanyAccessException;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;

/**
 * Server-side company/branch access resolution (P2-WS-2).
 *
 * Never trusts client-supplied tenant identifiers as authorization authority.
 */
final class CompanyAccessService
{
    public function activeMembership(User $user, Company $company): ?CompanyMembership
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            return null;
        }

        return CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->first();
    }

    public function isCompanyOwner(User $user, Company $company): bool
    {
        return $user->isCustomer()
            && $user->isActive()
            && $company->isOwnedBy($user)
            && $this->activeMembership($user, $company) !== null;
    }

    /**
     * Owner Panel access modes (P2-WS-6 / DEC-002).
     * Product UI for these scopes remains gated by P2-WS-8 design artefacts.
     */
    public const OWNER_PANEL_FULL = 'full';

    public const OWNER_PANEL_ADMIN_BILLING_ONLY = 'admin_billing_only';

    /**
     * Owner Panel: active Owner identity + Owner designation.
     * Company may be deactivated (limited admin/billing visibility only).
     */
    public function canAccessOwnerPanel(User $user, Company $company): bool
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            return false;
        }

        if ($user->isDeactivated() || $user->trashed()) {
            return false;
        }

        return $this->isCompanyOwner($user, $company);
    }

    /**
     * Server-side Owner Panel scope. Null when Owner Panel is denied.
     * Deactivated company → admin/billing information only (PO-P2-DEACT-COMPANY).
     */
    public function ownerPanelScope(User $user, Company $company): ?string
    {
        if (! $this->canAccessOwnerPanel($user, $company)) {
            return null;
        }

        return $company->isActive()
            ? self::OWNER_PANEL_FULL
            : self::OWNER_PANEL_ADMIN_BILLING_ONLY;
    }

    /**
     * Operational Branch Panel features are never available under the Owner Panel exception.
     */
    public function canPerformBranchPanelOperation(User $user, Company $company, ?Branch $branch = null): bool
    {
        return $this->canAccessBranchPanel($user, $company, $branch);
    }

    /**
     * Branch Panel: company must be active; Owner or explicit branch assignment.
     */
    public function canAccessBranchPanel(User $user, Company $company, ?Branch $branch = null): bool
    {
        if (! $company->isActive()) {
            return false;
        }

        if (! $user->isCustomer() || ! $user->isActive()) {
            return false;
        }

        if ($this->isCompanyOwner($user, $company)) {
            if ($branch === null) {
                return true;
            }

            return $branch->company_id === $company->id && $branch->isActive();
        }

        $membership = $this->activeMembership($user, $company);
        if ($membership === null) {
            return false;
        }

        if ($branch === null) {
            return $this->hasAnyActiveBranchAssignment($membership);
        }

        return $this->hasActiveBranchAssignment($membership, $branch);
    }

    public function canAccessBranch(User $user, Company $company, Branch $branch): bool
    {
        if ($branch->company_id !== $company->id) {
            return false;
        }

        return $this->canAccessBranchPanel($user, $company, $branch);
    }

    /**
     * Activate server-resolved tenant context into the request/job holder.
     *
     * Claimed company/branch identifiers are validated against membership/Owner access;
     * they are never treated as authorization authority by themselves.
     *
     * @throws CompanyAccessException
     */
    public function activateContext(User $user, Company $company, ?Branch $branch = null): TenantContext
    {
        $context = $this->resolveCompanyContext($user, $company, $branch);
        TenantContextHolder::inject($context);

        return $context;
    }

    /**
     * Resolve trusted tenant context for an authenticated Customer in a company workspace.
     *
     * @throws CompanyAccessException
     */
    public function resolveCompanyContext(User $user, Company $company, ?Branch $branch = null): TenantContext
    {
        if ($user->isInternal()) {
            throw new CompanyAccessException('Internal Team identities cannot enter Customer company workspace context.');
        }

        if (! $user->isCustomer() || ! $user->isActive()) {
            throw new CompanyAccessException('Inactive or non-Customer identity cannot resolve company context.');
        }

        $isOwner = $this->isCompanyOwner($user, $company);

        if ($branch === null) {
            if ($isOwner) {
                // Owner Panel / company-scoped context (including deactivated company for limited Owner Panel).
                if (! $this->canAccessOwnerPanel($user, $company) && ! $this->canAccessBranchPanel($user, $company)) {
                    throw new CompanyAccessException('Company context denied.');
                }

                return new TenantContext(
                    companyId: $company->id,
                    branchId: null,
                    ownerId: (string) $company->owner_user_id,
                    isInternal: false,
                    isOwnerScope: true,
                );
            }

            if (! $this->canAccessBranchPanel($user, $company)) {
                throw new CompanyAccessException('Company context denied for membership.');
            }

            return new TenantContext(
                companyId: $company->id,
                branchId: null,
                ownerId: (string) $company->owner_user_id,
                isInternal: false,
                isOwnerScope: false,
            );
        }

        if (! $this->canAccessBranch($user, $company, $branch)) {
            throw new CompanyAccessException('Branch context denied.');
        }

        return new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: $isOwner,
        );
    }

    private function hasActiveBranchAssignment(CompanyMembership $membership, Branch $branch): bool
    {
        if (! $branch->isActive()) {
            return false;
        }

        return BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('branch_id', $branch->id)
            ->where('company_id', $membership->company_id)
            ->whereNull('deactivated_at')
            ->exists();
    }

    private function hasAnyActiveBranchAssignment(CompanyMembership $membership): bool
    {
        return BranchAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('company_id', $membership->company_id)
            ->whereNull('deactivated_at')
            ->whereHas('branch', function ($query): void {
                $query->whereNull('deactivated_at')->whereNull('deleted_at');
            })
            ->exists();
    }
}
