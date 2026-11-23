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
            $this->seedCustomerPhase4Permissions();
            $this->seedCustomerPhase5Permissions();
            $this->seedCustomerPhase5InvoicePermissions();
            $this->seedCustomerPhase6Permissions();
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

    /**
     * PO-P4-PERM: register Enquiry/Quotation/LR codes without blanket role grants.
     */
    private function seedCustomerPhase4Permissions(): void
    {
        $definitions = [
            [PermissionCodes::ENQUIRY_VIEW, 'enquiry', null, 'View enquiries', 'View company/branch enquiries'],
            [PermissionCodes::ENQUIRY_CREATE, 'enquiry', null, 'Create enquiries', 'Create enquiries'],
            [PermissionCodes::ENQUIRY_EDIT, 'enquiry', null, 'Edit enquiries', 'Edit enquiries'],
            [PermissionCodes::ENQUIRY_ASSIGN, 'enquiry', null, 'Assign enquiries', 'Assign enquiries to branch/user'],
            [PermissionCodes::ENQUIRY_CONVERT, 'enquiry', null, 'Convert enquiries', 'Convert enquiries to Unified LR'],
            [PermissionCodes::ENQUIRY_DELETE, 'enquiry', null, 'Delete enquiries', 'Soft-delete enquiries (explicit only)'],
            [PermissionCodes::QUOTATION_VIEW, 'quotation', null, 'View quotations', 'View enquiry quotations'],
            [PermissionCodes::QUOTATION_CREATE, 'quotation', null, 'Create quotations', 'Create enquiry quotations'],
            [PermissionCodes::QUOTATION_EDIT, 'quotation', null, 'Edit quotations', 'Edit enquiry quotations'],
            [PermissionCodes::QUOTATION_SELECT, 'quotation', null, 'Select quotations', 'Select preferred quotation'],
            [PermissionCodes::QUOTATION_BROADCAST, 'quotation', null, 'Broadcast quotation requests', 'Enqueue quotation broadcast requests'],
            [PermissionCodes::LR_VIEW, 'lr', null, 'View LRs', 'View Unified Lorry Receipts'],
            [PermissionCodes::LR_CREATE, 'lr', null, 'Create LRs', 'Create Unified Lorry Receipts'],
            [PermissionCodes::LR_EDIT, 'lr', null, 'Edit LRs', 'Edit Unified Lorry Receipts'],
            [PermissionCodes::LR_CANCEL, 'lr', null, 'Cancel LRs', 'Cancel Unified Lorry Receipts'],
            [PermissionCodes::LR_DELETE, 'lr', null, 'Delete LRs', 'Soft-delete Unified Lorry Receipts (explicit only)'],
            [PermissionCodes::LR_NUMBER_ADJUST, 'lr', 'numbering', 'Adjust LR numbering', 'Adjust LR number sequences (restricted)'],
        ];

        foreach ($definitions as [$code, $module, $submodule, $name, $description]) {
            $this->upsertPermission(
                domain: PermissionDomain::Customer,
                module: $module,
                submodule: $submodule,
                name: $name,
                code: $code,
                description: $description,
            );
        }
    }

    /**
     * Phase 5 OwnFleet Trip codes — registered without blanket role grants.
     */
    /**
     * Phase 5 OwnFleet invoice / payment codes — registered without blanket role grants.
     */
    private function seedCustomerPhase5InvoicePermissions(): void
    {
        $definitions = [
            [PermissionCodes::INVOICE_VIEW, 'invoice', null, 'View Invoices', 'View OwnFleet customer invoices'],
            [PermissionCodes::INVOICE_CREATE, 'invoice', null, 'Create Invoices', 'Create OwnFleet customer invoices'],
            [PermissionCodes::INVOICE_EDIT, 'invoice', null, 'Edit Invoices', 'Edit OwnFleet invoice additional charges'],
            [PermissionCodes::INVOICE_CANCEL, 'invoice', null, 'Cancel Invoices', 'Cancel unpaid OwnFleet invoices'],
            [PermissionCodes::INVOICE_DELETE, 'invoice', null, 'Delete Invoices', 'Soft-delete latest unpaid OwnFleet invoices'],
            [PermissionCodes::INVOICE_PRINT, 'invoice', null, 'Print Invoices', 'Print sample OwnFleet invoices'],
            [PermissionCodes::PAYMENT_VIEW, 'payment', null, 'View Payments', 'View OwnFleet invoice payments'],
            [PermissionCodes::PAYMENT_CREATE, 'payment', null, 'Record Payments', 'Record OwnFleet invoice payments'],
        ];

        foreach ($definitions as [$code, $module, $submodule, $name, $description]) {
            $this->upsertPermission(
                domain: PermissionDomain::Customer,
                module: $module,
                submodule: $submodule,
                name: $name,
                code: $code,
                description: $description,
            );
        }
    }

    /**
     * Phase 6 Broker codes — registered without blanket role grants.
     */
    private function seedCustomerPhase6Permissions(): void
    {
        $definitions = [
            [PermissionCodes::BROKER_LORRY_HIRE_VIEW, 'broker', 'lorry_hire', 'View Lorry Hires', 'View Broker Lorry Hires'],
            [PermissionCodes::BROKER_LORRY_HIRE_CREATE, 'broker', 'lorry_hire', 'Create Lorry Hires', 'Create Lorry Hires from Broker LRs'],
            [PermissionCodes::BROKER_LORRY_HIRE_EDIT, 'broker', 'lorry_hire', 'Edit Lorry Hires', 'Edit Broker Lorry Hires'],
            [PermissionCodes::BROKER_LORRY_HIRE_CANCEL, 'broker', 'lorry_hire', 'Cancel Lorry Hires', 'Cancel Broker Lorry Hires'],
            [PermissionCodes::BROKER_VOUCHER_VIEW, 'broker', 'voucher', 'View Vouchers', 'View transporter vouchers'],
            [PermissionCodes::BROKER_VOUCHER_CREATE, 'broker', 'voucher', 'Create Vouchers', 'Create transporter vouchers'],
            [PermissionCodes::BROKER_VOUCHER_EDIT, 'broker', 'voucher', 'Edit Vouchers', 'Edit transporter vouchers'],
            [PermissionCodes::BROKER_VOUCHER_STATUS, 'broker', 'voucher', 'Update Voucher Status', 'Mark transporter voucher status'],
            [PermissionCodes::BROKER_VOUCHER_BULK, 'broker', 'voucher', 'Bulk Voucher Actions', 'Bulk transporter voucher operations'],
            [PermissionCodes::BROKER_DELIVERY_VIEW, 'broker', 'delivery', 'View Deliveries', 'View Broker delivery memos/POD'],
            [PermissionCodes::BROKER_DELIVERY_MANAGE, 'broker', 'delivery', 'Manage Deliveries', 'Manage Broker delivery memos/POD'],
            [PermissionCodes::BROKER_ADJUSTMENT_VIEW, 'broker', 'adjustment', 'View Adjustments', 'View post-delivery Broker adjustments'],
            [PermissionCodes::BROKER_ADJUSTMENT_CREATE, 'broker', 'adjustment', 'Create Adjustments', 'Create post-delivery Broker adjustments'],
            [PermissionCodes::BROKER_INVOICE_VIEW, 'broker', 'invoice', 'View Broker Invoices', 'View Broker customer invoices'],
            [PermissionCodes::BROKER_INVOICE_CREATE, 'broker', 'invoice', 'Create Broker Invoices', 'Create Broker customer invoices'],
            [PermissionCodes::BROKER_INVOICE_CANCEL, 'broker', 'invoice', 'Cancel Broker Invoices', 'Cancel Broker customer invoices'],
            [PermissionCodes::BROKER_INVOICE_PRINT, 'broker', 'invoice', 'Print Broker Invoices', 'Print Broker customer invoices'],
            [PermissionCodes::BROKER_LINKED_BILTY_VIEW, 'broker', 'linked_bilty', 'View Linked Bilties', 'View linked bilty groups'],
            [PermissionCodes::BROKER_LINKED_BILTY_CREATE, 'broker', 'linked_bilty', 'Create Linked Bilties', 'Create linked bilty groups'],
            [PermissionCodes::BROKER_LINKED_BILTY_PAY, 'broker', 'linked_bilty', 'Pay Linked Bilties', 'Record linked bilty payments'],
            [PermissionCodes::BROKER_BOOK_BALANCE_VIEW, 'broker', 'book_balance', 'View Book Balance', 'View transporter book balance'],
        ];

        foreach ($definitions as [$code, $module, $submodule, $name, $description]) {
            $this->upsertPermission(
                domain: PermissionDomain::Customer,
                module: $module,
                submodule: $submodule,
                name: $name,
                code: $code,
                description: $description,
            );
        }
    }

    private function seedCustomerPhase5Permissions(): void
    {
        $definitions = [
            [PermissionCodes::TRIP_VIEW, 'trip', null, 'View Trips', 'View OwnFleet Trips'],
            [PermissionCodes::TRIP_CREATE, 'trip', null, 'Create Trips', 'Create OwnFleet Trips from OwnFleet LRs'],
            [PermissionCodes::TRIP_EDIT, 'trip', null, 'Edit Trips', 'Edit OwnFleet Trip details and allocations'],
            [PermissionCodes::TRIP_DISPATCH, 'trip', null, 'Dispatch Trips', 'Dispatch OwnFleet Trips (Pending → In Progress)'],
            [PermissionCodes::TRIP_COMPLETE, 'trip', null, 'Complete Trips', 'Complete OwnFleet Trips'],
            [PermissionCodes::TRIP_CANCEL, 'trip', null, 'Cancel Trips', 'Cancel OwnFleet Trips (Owner/Admin business rule)'],
            [PermissionCodes::TRIP_DELETE, 'trip', null, 'Delete Trips', 'Soft-delete Pending OwnFleet Trips'],
        ];

        foreach ($definitions as [$code, $module, $submodule, $name, $description]) {
            $this->upsertPermission(
                domain: PermissionDomain::Customer,
                module: $module,
                submodule: $submodule,
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
