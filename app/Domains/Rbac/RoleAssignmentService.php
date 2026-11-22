<?php

namespace App\Domains\Rbac;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\IdentityLifecycleService;
use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Models\Branch;
use App\Models\BranchAssignment;
use App\Models\BranchRoleAssignment;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\InternalRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use InvalidArgumentException;

/**
 * Internal global role assignment and Customer branch role assignment.
 */
final class RoleAssignmentService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly PermissionCacheInvalidator $cache = new PermissionCacheInvalidator,
        private readonly CompanyAccessService $companyAccess = new CompanyAccessService,
        private readonly IdentityLifecycleService $identities = new IdentityLifecycleService,
    ) {}

    /**
     * Only Super Admin may create Internal users and optionally assign an ordinary Internal role.
     *
     * @param  array{name: string, email: string, password: string, is_super_admin?: bool}  $attributes
     */
    public function createInternalUser(User $actor, array $attributes, ?Role $ordinaryRole = null): User
    {
        $this->assertSuperAdmin($actor);

        if (($attributes['is_super_admin'] ?? false) === true && $ordinaryRole !== null) {
            throw new RbacDomainException('Super Admin privilege is not an ordinary Internal role assignment.');
        }

        if ($ordinaryRole !== null) {
            $this->assertAssignableInternalRole($ordinaryRole);
        }

        return $this->auditedTransaction->run(function () use ($attributes, $ordinaryRole): User {
            return $this->withInternalAuditContext(function () use ($attributes, $ordinaryRole): User {
                $user = $this->identities->create([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    'password' => $attributes['password'],
                    'identity_type' => IdentityType::Internal,
                    'is_super_admin' => (bool) ($attributes['is_super_admin'] ?? false),
                ]);

                if ($ordinaryRole !== null && ! $user->isSuperAdmin()) {
                    $this->assignInternalRoleWithinContext($user, $ordinaryRole, 'Initial Internal role assignment');
                }

                return $user;
            });
        });
    }

    public function assignInternalRole(User $actor, User $user, Role $role, string $remark): InternalRoleAssignment
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'Internal role assignment');
        $this->assertAssignableInternalRole($role);

        if (! $user->isInternal() || ! $user->isActive()) {
            throw new RbacDomainException('Only active Internal identities may receive ordinary Internal roles.');
        }

        if ($user->isSuperAdmin()) {
            throw new RbacDomainException('Super Admin privilege is independent of Permission Master roles.');
        }

        return $this->auditedTransaction->run(function () use ($user, $role, $remark): InternalRoleAssignment {
            return $this->withInternalAuditContext(function () use ($user, $role, $remark): InternalRoleAssignment {
                return $this->assignInternalRoleWithinContext($user, $role, $remark);
            });
        });
    }

    public function revokeInternalRole(User $actor, InternalRoleAssignment $assignment, string $remark): InternalRoleAssignment
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'Internal role revocation');

        if (! $assignment->isActive()) {
            throw new RbacDomainException('Internal role assignment is already inactive.');
        }

        return $this->auditedTransaction->run(function () use ($assignment, $remark): InternalRoleAssignment {
            return $this->withInternalAuditContext(function () use ($assignment, $remark): InternalRoleAssignment {
                $before = $this->internalAssignmentSnapshot($assignment);
                $assignment->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    InternalRoleAssignment::class,
                    $assignment->id,
                    remark: $remark,
                    before: $before,
                    after: $this->internalAssignmentSnapshot($assignment->refresh()),
                );

                $this->cache->bumpUser($assignment->user_id);

                return $assignment;
            });
        });
    }

    public function assignCustomerBranchRole(
        User $actor,
        CompanyMembership $membership,
        Branch $branch,
        BranchAssignment $branchAssignment,
        Role $role,
        string $remark,
    ): BranchRoleAssignment {
        $this->requireRemark($remark, 'Customer branch role assignment');
        $this->assertCanAssignCustomerRole($actor, Company::query()->findOrFail($membership->company_id));

        if (! $membership->isActive()) {
            throw new RbacDomainException('Inactive membership cannot receive branch roles.');
        }

        if (! $branch->isActive() || $branch->company_id !== $membership->company_id) {
            throw new RbacDomainException('Branch must be active and belong to the membership company.');
        }

        if (! $branchAssignment->isActive()
            || $branchAssignment->company_membership_id !== $membership->id
            || $branchAssignment->branch_id !== $branch->id
            || $branchAssignment->company_id !== $membership->company_id) {
            throw new RbacDomainException('An active matching branch assignment is required before role assignment.');
        }

        if ($role->domain !== PermissionDomain::Customer || ! $role->isActive()) {
            throw new RbacDomainException('Only active Customer-domain roles may be assigned at branch level.');
        }

        $member = User::query()->findOrFail($membership->user_id);
        if (! $member->isCustomer()) {
            throw new RbacDomainException('Customer branch roles cannot be assigned to Internal identities.');
        }

        if (BranchRoleAssignment::query()
            ->where('company_membership_id', $membership->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists()) {
            throw new RbacDomainException('An active branch role assignment already exists for this membership and branch.');
        }

        $company = Company::query()->findOrFail($membership->company_id);

        return $this->auditedTransaction->run(function () use ($company, $membership, $branch, $branchAssignment, $role, $remark, $member): BranchRoleAssignment {
            return $this->withCompanyAuditContext($company, function () use ($membership, $branch, $branchAssignment, $role, $remark, $member): BranchRoleAssignment {
                $assignment = new BranchRoleAssignment;
                $assignment->forceFill([
                    'company_id' => $membership->company_id,
                    'company_membership_id' => $membership->id,
                    'branch_id' => $branch->id,
                    'branch_assignment_id' => $branchAssignment->id,
                    'role_id' => $role->id,
                    'deactivated_at' => null,
                ]);
                $assignment->save();

                $this->auditWriter->write(
                    AuditAction::Created,
                    BranchRoleAssignment::class,
                    $assignment->id,
                    remark: $remark,
                    before: null,
                    after: $this->branchAssignmentSnapshot($assignment),
                );

                $this->cache->bumpUser($member);

                return $assignment->refresh();
            });
        });
    }

    public function revokeCustomerBranchRole(User $actor, BranchRoleAssignment $assignment, string $remark): BranchRoleAssignment
    {
        $this->requireRemark($remark, 'Customer branch role revocation');
        $company = Company::query()->findOrFail($assignment->company_id);
        $this->assertCanAssignCustomerRole($actor, $company);

        if (! $assignment->isActive()) {
            throw new RbacDomainException('Branch role assignment is already inactive.');
        }

        $membership = CompanyMembership::query()->findOrFail($assignment->company_membership_id);

        return $this->auditedTransaction->run(function () use ($company, $assignment, $remark, $membership): BranchRoleAssignment {
            return $this->withCompanyAuditContext($company, function () use ($assignment, $remark, $membership): BranchRoleAssignment {
                $before = $this->branchAssignmentSnapshot($assignment);
                $assignment->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    BranchRoleAssignment::class,
                    $assignment->id,
                    remark: $remark,
                    before: $before,
                    after: $this->branchAssignmentSnapshot($assignment->refresh()),
                );

                $this->cache->bumpUser($membership->user_id);

                return $assignment;
            });
        });
    }

    private function assignInternalRoleWithinContext(User $user, Role $role, string $remark): InternalRoleAssignment
    {
        $existing = InternalRoleAssignment::query()
            ->where('user_id', $user->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            $before = $this->internalAssignmentSnapshot($existing);
            $existing->forceFill(['deactivated_at' => now()])->save();

            $this->auditWriter->write(
                AuditAction::Updated,
                InternalRoleAssignment::class,
                $existing->id,
                remark: $remark,
                before: $before,
                after: $this->internalAssignmentSnapshot($existing->refresh()),
            );
        }

        $assignment = new InternalRoleAssignment;
        $assignment->forceFill([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'deactivated_at' => null,
        ]);
        $assignment->save();

        $this->auditWriter->write(
            AuditAction::Created,
            InternalRoleAssignment::class,
            $assignment->id,
            remark: $remark,
            before: null,
            after: $this->internalAssignmentSnapshot($assignment),
        );

        $this->cache->bumpUser($user);

        return $assignment->refresh();
    }

    private function assertAssignableInternalRole(Role $role): void
    {
        if ($role->domain !== PermissionDomain::Internal || ! $role->isActive()) {
            throw new RbacDomainException('Only active Internal-domain roles may be assigned to Internal identities.');
        }
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->isActive()) {
            throw new RbacAuthorizationException(
                'Only an active Super Admin may create Internal users or assign Internal roles.'
            );
        }
    }

    private function assertCanAssignCustomerRole(User $actor, Company $company): void
    {
        if ($actor->isSuperAdmin() && $actor->isActive()) {
            return;
        }

        if ($this->companyAccess->isCompanyOwner($actor, $company)) {
            return;
        }

        throw new RbacAuthorizationException(
            'Only the Company Owner (or Super Admin) may assign Customer branch roles.'
        );
    }

    private function requireRemark(string $remark, string $context): void
    {
        if (trim($remark) === '') {
            throw new InvalidArgumentException("A remark is required for {$context}.");
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withInternalAuditContext(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();

        TenantContextHolder::inject(new TenantContext(
            companyId: null,
            branchId: null,
            ownerId: null,
            isInternal: true,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withCompanyAuditContext(Company $company, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();

        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: null,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: true,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function internalAssignmentSnapshot(InternalRoleAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'role_id' => $assignment->role_id,
            'deactivated_at' => $assignment->deactivated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchAssignmentSnapshot(BranchRoleAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'company_id' => $assignment->company_id,
            'company_membership_id' => $assignment->company_membership_id,
            'branch_id' => $assignment->branch_id,
            'branch_assignment_id' => $assignment->branch_assignment_id,
            'role_id' => $assignment->role_id,
            'deactivated_at' => $assignment->deactivated_at?->toISOString(),
        ];
    }
}
