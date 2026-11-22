<?php

namespace App\Domains\Rbac;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Models\Branch;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\InternalRoleAssignment;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves effective active permission codes. Cache is advisory only.
 */
class PermissionResolver
{
    public function __construct(
        private readonly PermissionCacheInvalidator $cache = new PermissionCacheInvalidator,
        private readonly CompanyAccessService $companyAccess = new CompanyAccessService,
    ) {}

    /**
     * @return list<string>
     */
    public function codesForInternal(User $user): array
    {
        if (! $user->isInternal() || ! $user->isActive()) {
            return [];
        }

        // Super Admin is unrestricted via Authorizer — not via PM codes.
        if ($user->isSuperAdmin()) {
            return [];
        }

        return $this->remember(PermissionDomain::Internal, $user, null, function () use ($user): array {
            $assignment = InternalRoleAssignment::query()
                ->where('user_id', $user->id)
                ->whereNull('deactivated_at')
                ->whereNull('deleted_at')
                ->with('role')
                ->first();

            if ($assignment === null || $assignment->role === null || ! $assignment->role->isActive()) {
                return [];
            }

            if ($assignment->role->domain !== PermissionDomain::Internal) {
                return [];
            }

            return $this->activePermissionCodesForRole($assignment->role_id, PermissionDomain::Internal);
        });
    }

    /**
     * @return list<string>
     */
    public function codesForCustomerBranch(User $user, Company $company, Branch $branch): array
    {
        if (! $user->isCustomer() || ! $user->isActive()) {
            return [];
        }

        if ($branch->company_id !== $company->id) {
            return [];
        }

        // Owner Panel/branch access is not granted by ordinary roles.
        // Operational permissions still require an explicit branch role assignment.
        if (! $this->companyAccess->canAccessBranch($user, $company, $branch)) {
            return [];
        }

        // Owner without an ordinary branch role assignment gets no operational PM codes.
        // Owner special privileges remain in CompanyAccessService.

        return $this->remember(PermissionDomain::Customer, $user, $branch->id, function () use ($user, $company, $branch): array {
            $assignment = BranchRoleAssignment::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereNull('deactivated_at')
                ->whereNull('deleted_at')
                ->whereHas('membership', function ($query) use ($user): void {
                    $query->where('user_id', $user->id)
                        ->whereNull('deactivated_at')
                        ->whereNull('deleted_at');
                })
                ->whereHas('branchAssignment', function ($query): void {
                    $query->whereNull('deactivated_at')->whereNull('deleted_at');
                })
                ->with('role')
                ->first();

            if ($assignment === null || $assignment->role === null || ! $assignment->role->isActive()) {
                return [];
            }

            if ($assignment->role->domain !== PermissionDomain::Customer) {
                return [];
            }

            return $this->activePermissionCodesForRole($assignment->role_id, PermissionDomain::Customer);
        });
    }

    /**
     * @return list<string>
     */
    private function activePermissionCodesForRole(string $roleId, PermissionDomain $domain): array
    {
        $permissionIds = RolePermission::query()
            ->where('role_id', $roleId)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->pluck('permission_id');

        if ($permissionIds->isEmpty()) {
            return [];
        }

        /** @var list<string> $codes */
        $codes = Permission::query()
            ->whereIn('id', $permissionIds)
            ->where('domain', $domain->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->pluck('code')
            ->values()
            ->all();

        return $codes;
    }

    /**
     * @param  \Closure(): mixed  $resolver
     * @return list<string>
     */
    private function remember(PermissionDomain $domain, User $user, ?string $branchId, \Closure $resolver): array
    {
        $key = $this->cache->cacheKey($domain->value, (string) $user->id, $branchId);

        try {
            $cached = Cache::remember($key, now()->addMinutes(10), $resolver);

            if (! is_array($cached)) {
                throw new RbacDomainException('Permission resolution failed closed: invalid cache payload.');
            }

            $codes = [];
            foreach ($cached as $code) {
                if (is_string($code)) {
                    $codes[] = $code;
                }
            }

            return $codes;
        } catch (RbacDomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RbacDomainException(
                'Permission resolution failed closed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }
}
