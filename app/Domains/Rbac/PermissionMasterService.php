<?php

namespace App\Domains\Rbac;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use InvalidArgumentException;

/**
 * Permission Master and role definition mutations (Internal Super Admin only).
 */
final class PermissionMasterService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly PermissionCacheInvalidator $cache = new PermissionCacheInvalidator,
    ) {}

    /**
     * @param  array{domain: PermissionDomain, module: string, submodule?: ?string, name: string, code: string, description?: ?string}  $attributes
     */
    public function createPermission(User $actor, array $attributes): Permission
    {
        $this->assertSuperAdmin($actor);

        return $this->auditedTransaction->run(function () use ($attributes): Permission {
            return $this->withInternalAuditContext(function () use ($attributes): Permission {
                $permission = new Permission;
                $permission->forceFill([
                    'domain' => $attributes['domain'],
                    'module' => $attributes['module'],
                    'submodule' => $attributes['submodule'] ?? null,
                    'name' => $attributes['name'],
                    'code' => $attributes['code'],
                    'description' => $attributes['description'] ?? null,
                    'deactivated_at' => null,
                ]);
                $permission->save();

                $this->auditWriter->write(
                    AuditAction::Created,
                    Permission::class,
                    $permission->id,
                    remark: null,
                    before: null,
                    after: $this->permissionSnapshot($permission),
                );

                $this->cache->bumpGlobal();

                return $permission->refresh();
            });
        });
    }

    /**
     * @param  array{module?: string, submodule?: ?string, name?: string, description?: ?string, domain?: mixed, code?: mixed}  $attributes
     */
    public function updatePermission(User $actor, Permission $permission, array $attributes, string $remark): Permission
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'permission updates');

        return $this->auditedTransaction->run(function () use ($permission, $attributes, $remark): Permission {
            return $this->withInternalAuditContext(function () use ($permission, $attributes, $remark): Permission {
                $before = $this->permissionSnapshot($permission);

                $permission->forceFill([
                    'module' => $attributes['module'] ?? $permission->module,
                    'submodule' => array_key_exists('submodule', $attributes) ? $attributes['submodule'] : $permission->submodule,
                    'name' => $attributes['name'] ?? $permission->name,
                    'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $permission->description,
                ])->save();

                // Domain and code are immutable after create.
                if (isset($attributes['domain']) || isset($attributes['code'])) {
                    throw new RbacDomainException('Permission domain and code cannot be changed after creation.');
                }

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Permission::class,
                    $permission->id,
                    remark: $remark,
                    before: $before,
                    after: $this->permissionSnapshot($permission->refresh()),
                );

                $this->cache->bumpGlobal();

                return $permission;
            });
        });
    }

    public function deactivatePermission(User $actor, Permission $permission, string $remark): Permission
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'permission deactivation');

        if (! $permission->isActive()) {
            throw new RbacDomainException('Permission is already deactivated.');
        }

        return $this->auditedTransaction->run(function () use ($permission, $remark): Permission {
            return $this->withInternalAuditContext(function () use ($permission, $remark): Permission {
                $before = $this->permissionSnapshot($permission);
                $permission->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Permission::class,
                    $permission->id,
                    remark: $remark,
                    before: $before,
                    after: $this->permissionSnapshot($permission->refresh()),
                );

                $this->cache->bumpGlobal();

                return $permission;
            });
        });
    }

    /**
     * @param  array{domain: PermissionDomain, name: string, slug: string, description?: ?string, is_default?: bool, is_system?: bool}  $attributes
     */
    public function createRole(User $actor, array $attributes): Role
    {
        $this->assertSuperAdmin($actor);

        return $this->auditedTransaction->run(function () use ($attributes): Role {
            return $this->withInternalAuditContext(function () use ($attributes): Role {
                $role = new Role;
                $role->forceFill([
                    'domain' => $attributes['domain'],
                    'name' => $attributes['name'],
                    'slug' => $attributes['slug'],
                    'description' => $attributes['description'] ?? null,
                    'is_default' => (bool) ($attributes['is_default'] ?? false),
                    'is_system' => (bool) ($attributes['is_system'] ?? false),
                    'deactivated_at' => null,
                ]);
                $role->save();

                $this->auditWriter->write(
                    AuditAction::Created,
                    Role::class,
                    $role->id,
                    remark: null,
                    before: null,
                    after: $this->roleSnapshot($role),
                );

                $this->cache->bumpGlobal();

                return $role->refresh();
            });
        });
    }

    /**
     * @param  array{name?: string, description?: ?string, domain?: mixed, slug?: mixed}  $attributes
     */
    public function updateRole(User $actor, Role $role, array $attributes, string $remark): Role
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'role updates');

        return $this->auditedTransaction->run(function () use ($role, $attributes, $remark): Role {
            return $this->withInternalAuditContext(function () use ($role, $attributes, $remark): Role {
                $before = $this->roleSnapshot($role);

                if (isset($attributes['domain']) || isset($attributes['slug'])) {
                    throw new RbacDomainException('Role domain and slug cannot be changed after creation.');
                }

                $role->forceFill([
                    'name' => $attributes['name'] ?? $role->name,
                    'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $role->description,
                ])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Role::class,
                    $role->id,
                    remark: $remark,
                    before: $before,
                    after: $this->roleSnapshot($role->refresh()),
                );

                $this->cache->bumpGlobal();

                return $role;
            });
        });
    }

    public function deactivateRole(User $actor, Role $role, string $remark): Role
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'role deactivation');

        if (! $role->isActive()) {
            throw new RbacDomainException('Role is already deactivated.');
        }

        return $this->auditedTransaction->run(function () use ($role, $remark): Role {
            return $this->withInternalAuditContext(function () use ($role, $remark): Role {
                $before = $this->roleSnapshot($role);
                $role->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Role::class,
                    $role->id,
                    remark: $remark,
                    before: $before,
                    after: $this->roleSnapshot($role->refresh()),
                );

                $this->cache->bumpGlobal();

                return $role;
            });
        });
    }

    public function assignPermissionToRole(User $actor, Role $role, Permission $permission, string $remark): RolePermission
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'role-permission assignment');

        if (! $role->isActive()) {
            throw new RbacDomainException('Deactivated roles cannot receive new permissions.');
        }

        if (! $permission->isActive()) {
            throw new RbacDomainException('Deactivated permissions cannot be newly assigned.');
        }

        if ($role->domain !== $permission->domain) {
            throw new RbacDomainException('Role and permission domains must match.');
        }

        if (RolePermission::query()
            ->where('role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists()) {
            throw new RbacDomainException('Permission is already assigned to this role.');
        }

        return $this->auditedTransaction->run(function () use ($role, $permission, $remark): RolePermission {
            return $this->withInternalAuditContext(function () use ($role, $permission, $remark): RolePermission {
                $pivot = new RolePermission;
                $pivot->forceFill([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                    'deactivated_at' => null,
                ]);
                $pivot->save();

                $this->auditWriter->write(
                    AuditAction::Created,
                    RolePermission::class,
                    $pivot->id,
                    remark: $remark,
                    before: null,
                    after: $this->rolePermissionSnapshot($pivot),
                );

                $this->cache->bumpGlobal();

                return $pivot->refresh();
            });
        });
    }

    public function revokePermissionFromRole(User $actor, RolePermission $rolePermission, string $remark): RolePermission
    {
        $this->assertSuperAdmin($actor);
        $this->requireRemark($remark, 'role-permission revocation');

        if (! $rolePermission->isActive()) {
            throw new RbacDomainException('Role-permission link is already inactive.');
        }

        return $this->auditedTransaction->run(function () use ($rolePermission, $remark): RolePermission {
            return $this->withInternalAuditContext(function () use ($rolePermission, $remark): RolePermission {
                $before = $this->rolePermissionSnapshot($rolePermission);
                $rolePermission->forceFill(['deactivated_at' => now()])->save();

                $this->auditWriter->write(
                    AuditAction::Updated,
                    RolePermission::class,
                    $rolePermission->id,
                    remark: $remark,
                    before: $before,
                    after: $this->rolePermissionSnapshot($rolePermission->refresh()),
                );

                $this->cache->bumpGlobal();

                return $rolePermission;
            });
        });
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (! $actor->isSuperAdmin() || ! $actor->isActive()) {
            throw new RbacAuthorizationException(
                'Only an active Super Admin may manage Permission Master definitions.'
            );
        }
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
     * @return array<string, mixed>
     */
    private function permissionSnapshot(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'domain' => $permission->domain->value,
            'module' => $permission->module,
            'submodule' => $permission->submodule,
            'name' => $permission->name,
            'code' => $permission->code,
            'description' => $permission->description,
            'deactivated_at' => $permission->deactivated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function roleSnapshot(Role $role): array
    {
        return [
            'id' => $role->id,
            'domain' => $role->domain->value,
            'name' => $role->name,
            'slug' => $role->slug,
            'is_default' => $role->is_default,
            'is_system' => $role->is_system,
            'deactivated_at' => $role->deactivated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rolePermissionSnapshot(RolePermission $pivot): array
    {
        return [
            'id' => $pivot->id,
            'role_id' => $pivot->role_id,
            'permission_id' => $pivot->permission_id,
            'deactivated_at' => $pivot->deactivated_at?->toISOString(),
        ];
    }
}
