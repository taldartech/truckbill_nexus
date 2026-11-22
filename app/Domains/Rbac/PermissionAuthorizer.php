<?php

namespace App\Domains\Rbac;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;

/**
 * Server-side action authorization (P2-WS-3).
 *
 * Never trusts client-supplied role/permission/company/branch identifiers as authority.
 */
final class PermissionAuthorizer
{
    public function __construct(
        private readonly PermissionResolver $resolver = new PermissionResolver,
    ) {}

    public function allowsInternal(User $user, string $permissionCode): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->isInternal()) {
            return false;
        }

        return in_array($permissionCode, $this->resolver->codesForInternal($user), true);
    }

    public function allowsCustomer(User $user, string $permissionCode, Company $company, Branch $branch): bool
    {
        if (! $user->isActive() || ! $user->isCustomer()) {
            return false;
        }

        // Operational permissions never grant Owner Panel; Owner designation is separate.
        // Owner still needs an ordinary role assignment for operational PM codes.
        return in_array(
            $permissionCode,
            $this->resolver->codesForCustomerBranch($user, $company, $branch),
            true,
        );
    }

    public function allows(User $user, string $permissionCode, ?Company $company = null, ?Branch $branch = null): bool
    {
        if ($user->isInternal()) {
            return $this->allowsInternal($user, $permissionCode);
        }

        if ($user->isCustomer()) {
            if ($company === null || $branch === null) {
                return false;
            }

            return $this->allowsCustomer($user, $permissionCode, $company, $branch);
        }

        return false;
    }

    public function domainFor(User $user): ?PermissionDomain
    {
        if ($user->isInternal()) {
            return PermissionDomain::Internal;
        }

        if ($user->isCustomer()) {
            return PermissionDomain::Customer;
        }

        return null;
    }
}
