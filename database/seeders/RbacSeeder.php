<?php

namespace Database\Seeders;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent Permission Master seed (P2-WS-3).
 *
 * Customer default matrix follows PRD §6. Delete is never assigned by default.
 * Internal ordinary roles are seeded without permissions (configurable by Super Admin).
 * Internal CRM dashboard permission codes remain unresolved until those modules are authorized.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedCustomerPlatformPermissions();
            $this->seedInternalRoles();
            $this->seedCustomerDefaultRoles();
            $this->seedCustomerDefaultMatrix();
        });
    }

    private function seedCustomerPlatformPermissions(): void
    {
        $definitions = [
            [PermissionCodes::PLATFORM_VIEW, 'View', 'View operational records'],
            [PermissionCodes::PLATFORM_CREATE, 'Create', 'Create operational records'],
            [PermissionCodes::PLATFORM_EDIT, 'Edit', 'Edit operational records'],
            [PermissionCodes::PLATFORM_DELETE, 'Delete', 'Soft-delete operational records (explicit only)'],
            [PermissionCodes::PLATFORM_CANCEL, 'Cancel', 'Cancel operational records (subject to business restrictions)'],
            [PermissionCodes::PLATFORM_PRINT, 'Print', 'Print operational documents'],
            [PermissionCodes::PLATFORM_EXPORT, 'Export', 'Export operational data'],
            [PermissionCodes::PLATFORM_MANAGE_SETTINGS, 'Manage operational settings', 'Manage branch operational settings'],
            [PermissionCodes::PLATFORM_VIEW_FREIGHT, 'View freight', 'View freight amounts'],
        ];

        foreach ($definitions as [$code, $name, $description]) {
            $this->upsertPermission(
                domain: PermissionDomain::Customer,
                module: 'platform',
                submodule: 'operations',
                name: $name,
                code: $code,
                description: $description,
            );
        }
    }

    private function seedInternalRoles(): void
    {
        foreach ([
            ['Admin', 'internal.admin'],
            ['Sales Executive', 'internal.sales_executive'],
            ['Support Executive', 'internal.support_executive'],
        ] as [$name, $slug]) {
            $this->upsertRole(
                domain: PermissionDomain::Internal,
                name: $name,
                slug: $slug,
                isDefault: true,
                isSystem: true,
                description: 'Ordinary Internal role (permissions configured by Super Admin).',
            );
        }
    }

    private function seedCustomerDefaultRoles(): void
    {
        foreach ([
            ['Admin', 'customer.admin'],
            ['Operator', 'customer.operator'],
            ['Viewer', 'customer.viewer'],
            ['Operator–No Freight', 'customer.operator_no_freight'],
            ['Viewer–No Freight', 'customer.viewer_no_freight'],
        ] as [$name, $slug]) {
            $this->upsertRole(
                domain: PermissionDomain::Customer,
                name: $name,
                slug: $slug,
                isDefault: true,
                isSystem: true,
                description: 'Customer default role (PRD §6).',
            );
        }
    }

    private function seedCustomerDefaultMatrix(): void
    {
        $matrix = [
            'customer.admin' => [
                PermissionCodes::PLATFORM_VIEW,
                PermissionCodes::PLATFORM_CREATE,
                PermissionCodes::PLATFORM_EDIT,
                PermissionCodes::PLATFORM_CANCEL,
                PermissionCodes::PLATFORM_PRINT,
                PermissionCodes::PLATFORM_EXPORT,
                PermissionCodes::PLATFORM_MANAGE_SETTINGS,
                PermissionCodes::PLATFORM_VIEW_FREIGHT,
                // Delete intentionally omitted — explicit only.
            ],
            'customer.operator' => [
                PermissionCodes::PLATFORM_VIEW,
                PermissionCodes::PLATFORM_CREATE,
                PermissionCodes::PLATFORM_PRINT,
                PermissionCodes::PLATFORM_VIEW_FREIGHT,
            ],
            'customer.viewer' => [
                PermissionCodes::PLATFORM_VIEW,
                PermissionCodes::PLATFORM_PRINT,
                PermissionCodes::PLATFORM_VIEW_FREIGHT,
            ],
            'customer.operator_no_freight' => [
                PermissionCodes::PLATFORM_VIEW,
                PermissionCodes::PLATFORM_CREATE,
                PermissionCodes::PLATFORM_PRINT,
            ],
            'customer.viewer_no_freight' => [
                PermissionCodes::PLATFORM_VIEW,
                PermissionCodes::PLATFORM_PRINT,
            ],
        ];

        foreach ($matrix as $slug => $codes) {
            $role = Role::query()->where('slug', $slug)->where('domain', PermissionDomain::Customer->value)->firstOrFail();

            foreach ($codes as $code) {
                $permission = Permission::query()->where('code', $code)->firstOrFail();
                $this->ensureRolePermission($role, $permission);
            }
        }
    }

    private function upsertPermission(
        PermissionDomain $domain,
        string $module,
        ?string $submodule,
        string $name,
        string $code,
        string $description,
    ): Permission {
        $permission = Permission::query()->where('code', $code)->first();

        if ($permission === null) {
            $permission = new Permission;
            $permission->forceFill([
                'domain' => $domain,
                'module' => $module,
                'submodule' => $submodule,
                'name' => $name,
                'code' => $code,
                'description' => $description,
                'deactivated_at' => null,
            ]);
            $permission->save();

            return $permission;
        }

        $permission->forceFill([
            'domain' => $domain,
            'module' => $module,
            'submodule' => $submodule,
            'name' => $name,
            'description' => $description,
        ])->save();

        return $permission;
    }

    private function upsertRole(
        PermissionDomain $domain,
        string $name,
        string $slug,
        bool $isDefault,
        bool $isSystem,
        string $description,
    ): Role {
        $role = Role::query()
            ->where('domain', $domain->value)
            ->where('slug', $slug)
            ->first();

        if ($role === null) {
            $role = new Role;
            $role->forceFill([
                'domain' => $domain,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'is_default' => $isDefault,
                'is_system' => $isSystem,
                'deactivated_at' => null,
            ]);
            $role->save();

            return $role;
        }

        $role->forceFill([
            'name' => $name,
            'description' => $description,
            'is_default' => $isDefault,
            'is_system' => $isSystem,
        ])->save();

        return $role;
    }

    private function ensureRolePermission(Role $role, Permission $permission): void
    {
        $exists = RolePermission::query()
            ->where('role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return;
        }

        $pivot = new RolePermission;
        $pivot->forceFill([
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'deactivated_at' => null,
        ]);
        $pivot->save();
    }
}
