<?php

namespace App\Domains\Rbac;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Models\BranchRoleAssignment;
use App\Models\InternalRoleAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Centralized Internal Team & Customer Roles and Permissions management service.
 *
 * Reuses PermissionAuthorizer, Role, Permission, RolePermission, and PermissionCacheInvalidator.
 * Enforces strict domain separation, authority ceiling (non-Super-Admin cannot grant permissions
 * they do not hold), mandatory remarks, and immediate cache invalidation.
 */
final class RolePermissionManagementService
{
    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionCacheInvalidator $cache = new PermissionCacheInvalidator,
        private readonly PermissionResolver $resolver = new PermissionResolver,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    /**
     * @return array{
     *     internal_roles: list<array<string, mixed>>,
     *     customer_roles: list<array<string, mixed>>,
     *     audit_history: list<array<string, mixed>>
     * }
     */
    public function registerSnapshot(): array
    {
        $roles = Role::query()
            ->whereNull('deleted_at')
            ->orderBy('domain')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $internalCounts = InternalRoleAssignment::query()
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $customerCounts = BranchRoleAssignment::query()
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $permissionCounts = RolePermission::query()
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $internal = [];
        $customer = [];

        foreach ($roles as $role) {
            $domain = $role->domain;
            $row = [
                'id' => $role->id,
                'domain' => $domain->value,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_system' => (bool) $role->is_system,
                'is_default' => (bool) $role->is_default,
                'is_active' => $role->isActive(),
                'assigned_user_count' => (int) ($domain === PermissionDomain::Internal
                    ? ($internalCounts[$role->id] ?? 0)
                    : ($customerCounts[$role->id] ?? 0)),
                'permission_count' => (int) ($permissionCounts[$role->id] ?? 0),
                'edit_url' => route('admin.roles.edit', $role),
                'toggle_url' => route('admin.roles.toggle', $role),
            ];

            if ($domain === PermissionDomain::Internal) {
                $internal[] = $row;
            } else {
                $customer[] = $row;
            }
        }

        $auditRows = AuditEntry::query()
            ->whereIn('subject_type', [Role::class, RolePermission::class, 'role_permission_sync'])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        $actorNames = User::query()
            ->whereIn('id', $auditRows->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        $auditLogs = array_values($auditRows->map(fn (AuditEntry $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'subject_type' => class_basename($log->subject_type),
            'subject_id' => $log->subject_id,
            'remark' => $log->remark,
            'actor_name' => $actorNames[$log->actor_id ?? ''] ?? 'System',
            'created_at' => $log->created_at->toIso8601String(),
        ])->all());

        return [
            'internal_roles' => $internal,
            'customer_roles' => $customer,
            'audit_history' => $auditLogs,
        ];
    }

    /**
     * Build compact grouped permission matrix strictly from actual active Permission records.
     *
     * @return array{
     *     actions: list<array{key: string, label: string}>,
     *     groups: list<array{
     *         key: string,
     *         label: string,
     *         rows: list<array{
     *             resource_key: string,
     *             resource_label: string,
     *             cells: array<string, array{id: string, code: string, name: string, grantable: bool}>
     *         }>
     *     }>,
     *     assigned_permission_ids: list<string>
     * }
     */
    public function matrixForRole(User $actor, Role $role): array
    {
        $permissions = Permission::query()
            ->where('domain', $role->domain->value)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->orderBy('module')
            ->orderBy('submodule')
            ->orderBy('code')
            ->get();

        $assignedIds = RolePermission::query()
            ->where('role_id', $role->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->pluck('permission_id')
            ->values()
            ->all();

        $actorHeldCodes = $this->actorPermittedCodes($actor, $role->domain);
        $isSuperAdmin = $actor->isSuperAdmin();

        $actionLabels = [
            'view' => 'View',
            'create' => 'Create',
            'edit' => 'Edit',
            'delete' => 'Delete',
            'approve' => 'Approve',
            'cancel' => 'Cancel',
            'print' => 'Print',
            'export' => 'Export',
            'settle' => 'Settle',
            'reverse' => 'Reverse',
            'manage' => 'Manage',
            'view_freight' => 'View Freight',
            'view_financial' => 'View Financial',
            'other' => 'Special',
        ];

        $presentActionKeys = [];
        $grouped = [];

        foreach ($permissions as $permission) {
            [$groupKey, $groupLabel] = $this->resolveGroup($role->domain, $permission);
            [$resourceKey, $resourceLabel] = $this->resolveResource($permission);
            $actionKey = $this->resolveActionKey($permission->code);
            $presentActionKeys[$actionKey] = true;

            $grantable = $isSuperAdmin
                || ($role->domain === PermissionDomain::Customer && $this->internalAuth->allows($actor, PermissionCodes::INTERNAL_SETTINGS_MANAGE))
                || in_array($permission->code, $actorHeldCodes, true);

            $grouped[$groupKey] ??= [
                'key' => $groupKey,
                'label' => $groupLabel,
                'rows_map' => [],
            ];

            $grouped[$groupKey]['rows_map'][$resourceKey] ??= [
                'resource_key' => $resourceKey,
                'resource_label' => $resourceLabel,
                'cells' => [],
            ];

            // Avoid colliding inside a single cell if two specialized codes map to 'other'.
            $cellKey = isset($grouped[$groupKey]['rows_map'][$resourceKey]['cells'][$actionKey])
                ? 'other'
                : $actionKey;

            $grouped[$groupKey]['rows_map'][$resourceKey]['cells'][$cellKey] = [
                'id' => $permission->id,
                'code' => $permission->code,
                'name' => $permission->name,
                'grantable' => $grantable,
            ];
        }

        $orderedActions = [];
        foreach ($actionLabels as $key => $label) {
            if (isset($presentActionKeys[$key])) {
                $orderedActions[] = ['key' => $key, 'label' => $label];
            }
        }

        $groups = [];
        foreach ($this->groupOrder($role->domain) as $key) {
            if (! isset($grouped[$key])) {
                continue;
            }
            $groups[] = [
                'key' => $grouped[$key]['key'],
                'label' => $grouped[$key]['label'],
                'rows' => array_values($grouped[$key]['rows_map']),
            ];
            unset($grouped[$key]);
        }
        foreach ($grouped as $leftover) {
            $groups[] = [
                'key' => $leftover['key'],
                'label' => $leftover['label'],
                'rows' => array_values($leftover['rows_map']),
            ];
        }

        return [
            'actions' => $orderedActions,
            'groups' => $groups,
            'assigned_permission_ids' => array_values(array_map('strval', $assignedIds)),
        ];
    }

    /**
     * @param  array{domain: string, name: string, description?: ?string}  $input
     */
    public function createRole(User $actor, array $input): Role
    {
        $this->assertCanManage($actor);

        $domain = PermissionDomain::from($input['domain']);
        $name = trim($input['name']);
        if ($name === '') {
            throw new InvalidArgumentException('Role name is required.');
        }

        $baseSlug = $domain->value.'.'.Str::slug($name, '_');
        $slug = $baseSlug;
        $counter = 2;
        while (Role::query()->where('domain', $domain->value)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'_'.$counter;
            $counter++;
        }

        return $this->tx->run(function () use ($domain, $name, $slug, $input): Role {
            return $this->withInternalContext(function () use ($domain, $name, $slug, $input): Role {
                $role = new Role;
                $role->forceFill([
                    'domain' => $domain,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => isset($input['description']) ? trim((string) $input['description']) : null,
                    'is_default' => false,
                    'is_system' => false,
                    'deactivated_at' => null,
                ]);
                $role->save();

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: Role::class,
                    subjectId: $role->id,
                    remark: 'Create '.$domain->value.' role '.$name,
                    before: null,
                    after: $role->toArray(),
                );

                $this->cache->bumpGlobal();

                return $role->fresh() ?? $role;
            });
        });
    }

    /**
     * @param  array{name: string, description?: ?string}  $input
     */
    public function updateRoleDetails(User $actor, Role $role, array $input, string $remark): Role
    {
        $this->assertCanManage($actor);
        if (trim($remark) === '') {
            throw new InvalidArgumentException('Edit remark is required.');
        }

        return $this->tx->run(function () use ($role, $input, $remark): Role {
            return $this->withInternalContext(function () use ($role, $input, $remark): Role {
                $before = $role->toArray();
                $role->forceFill([
                    'name' => trim($input['name']),
                    'description' => array_key_exists('description', $input) ? $input['description'] : $role->description,
                ])->save();

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: Role::class,
                    subjectId: $role->id,
                    remark: $remark,
                    before: $before,
                    after: $role->fresh()?->toArray(),
                );

                $this->cache->bumpGlobal();

                return $role->fresh() ?? $role;
            });
        });
    }

    public function toggleRoleStatus(User $actor, Role $role, string $remark): Role
    {
        $this->assertCanManage($actor);
        if (trim($remark) === '') {
            throw new InvalidArgumentException('Edit remark is required.');
        }

        if ($role->is_system && $role->isActive()) {
            throw new RbacDomainException('System default roles cannot be deactivated.');
        }

        return $this->tx->run(function () use ($role, $remark): Role {
            return $this->withInternalContext(function () use ($role, $remark): Role {
                $before = $role->toArray();
                $role->forceFill([
                    'deactivated_at' => $role->isActive() ? now() : null,
                ])->save();

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: Role::class,
                    subjectId: $role->id,
                    remark: $remark,
                    before: $before,
                    after: $role->fresh()?->toArray(),
                );

                $this->cache->bumpGlobal();

                return $role->fresh() ?? $role;
            });
        });
    }

    /**
     * Synchronize permissions on a Role inside one audited transaction with authority ceiling enforcement.
     *
     * @param  list<string>  $permissionIds
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncRolePermissions(User $actor, Role $role, array $permissionIds, string $remark): array
    {
        $this->assertCanManage($actor);
        if (trim($remark) === '') {
            throw new InvalidArgumentException('A remark is required for permission changes.');
        }

        if (! $role->isActive()) {
            throw new RbacDomainException('Inactive roles cannot have permissions modified.');
        }

        $uniqueIds = array_values(array_unique(array_map('strval', $permissionIds)));

        /** @var Collection<string, Permission> $requestedPermissions */
        $requestedPermissions = Permission::query()
            ->whereIn('id', $uniqueIds)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        if ($requestedPermissions->count() !== count($uniqueIds)) {
            throw new RbacDomainException('One or more selected permissions are invalid or inactive.');
        }

        foreach ($requestedPermissions as $perm) {
            if ($perm->domain !== $role->domain) {
                throw new RbacDomainException("Permission \"{$perm->code}\" does not belong to the {$role->domain->value} domain.");
            }
        }

        $currentPivots = RolePermission::query()
            ->where('role_id', $role->id)
            ->whereNull('deactivated_at')
            ->whereNull('deleted_at')
            ->with('permission')
            ->get();

        $currentIds = $currentPivots->pluck('permission_id')->map('strval')->values()->all();
        $toAdd = array_values(array_diff($uniqueIds, $currentIds));
        $toRemove = array_values(array_diff($currentIds, $uniqueIds));

        // Enforce Authority Ceiling for non-Super-Admin internal administrators:
        // Never allow granting OR revoking Internal permissions exceeding actor's own active permissions.
        if (! $actor->isSuperAdmin()) {
            $actorCodes = $this->resolver->codesForInternal($actor);
            if ($role->domain === PermissionDomain::Internal) {
                foreach ($toAdd as $addId) {
                    $perm = $requestedPermissions->get($addId);
                    if ($perm !== null && ! in_array($perm->code, $actorCodes, true)) {
                        throw new RbacAuthorizationException("You cannot grant permission \"{$perm->code}\" because you do not hold it.");
                    }
                }
                foreach ($toRemove as $removeId) {
                    $pivot = $currentPivots->firstWhere('permission_id', $removeId);
                    $code = $pivot?->permission?->code;
                    if ($code !== null && ! in_array($code, $actorCodes, true)) {
                        throw new RbacAuthorizationException("You cannot revoke permission \"{$code}\" because it exceeds your authority.");
                    }
                }
            }
        }

        return $this->tx->run(function () use ($role, $currentPivots, $requestedPermissions, $toAdd, $toRemove, $remark): array {
            return $this->withInternalContext(function () use ($role, $currentPivots, $requestedPermissions, $toAdd, $toRemove, $remark): array {
                $beforeCodes = $currentPivots->pluck('permission.code')->filter()->sort()->values()->all();

                $removedCodes = [];
                foreach ($toRemove as $permId) {
                    $pivot = $currentPivots->firstWhere('permission_id', $permId);
                    if ($pivot !== null) {
                        $pivot->forceFill(['deactivated_at' => now()])->save();
                        $removedCodes[] = $pivot->permission !== null ? $pivot->permission->code : $permId;
                    }
                }

                $addedCodes = [];
                foreach ($toAdd as $permId) {
                    $pivot = new RolePermission;
                    $pivot->forceFill([
                        'role_id' => $role->id,
                        'permission_id' => $permId,
                        'deactivated_at' => null,
                    ]);
                    $pivot->save();
                    $requestedPermission = $requestedPermissions->get($permId);
                    $addedCodes[] = $requestedPermission !== null ? $requestedPermission->code : $permId;
                }

                $afterCodes = RolePermission::query()
                    ->where('role_id', $role->id)
                    ->whereNull('deactivated_at')
                    ->whereNull('deleted_at')
                    ->with('permission')
                    ->get()
                    ->pluck('permission.code')
                    ->filter()
                    ->sort()
                    ->values()
                    ->all();

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: Role::class,
                    subjectId: $role->id,
                    remark: $remark,
                    before: ['permissions' => $beforeCodes],
                    after: ['permissions' => $afterCodes],
                );

                $this->cache->bumpGlobal();

                return [
                    'added' => $addedCodes,
                    'removed' => $removedCodes,
                ];
            });
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function roleAuditHistory(Role $role): array
    {
        $rows = AuditEntry::query()
            ->where('subject_type', Role::class)
            ->where('subject_id', $role->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $actorNames = User::query()
            ->whereIn('id', $rows->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return array_values($rows->map(fn (AuditEntry $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'remark' => $log->remark,
            'before' => $log->before,
            'after' => $log->after,
            'actor_name' => $actorNames[$log->actor_id ?? ''] ?? 'System',
            'created_at' => $log->created_at->toIso8601String(),
        ])->all());
    }

    private function assertCanManage(User $actor): void
    {
        if (! $this->internalAuth->allows($actor, PermissionCodes::INTERNAL_SETTINGS_MANAGE)) {
            throw new RbacAuthorizationException('You are not authorized to configure roles and permissions.');
        }
    }

    /**
     * @return list<string>
     */
    private function actorPermittedCodes(User $actor, PermissionDomain $domain): array
    {
        if ($actor->isSuperAdmin()) {
            return [];
        }

        return $domain === PermissionDomain::Internal
            ? $this->resolver->codesForInternal($actor)
            : [];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveGroup(PermissionDomain $domain, Permission $permission): array
    {
        if ($domain === PermissionDomain::Internal) {
            return match ($permission->module) {
                'crm' => ['crm', 'CRM'],
                'internal_users' => ['internal_users', 'Internal Users'],
                'customer_onboarding' => ['customer_onboarding', 'Customer Onboarding'],
                'platform_masters' => ['platform_masters', 'Platform Masters'],
                'gst' => ['gst', 'GST'],
                'tds' => ['tds', 'TDS'],
                'subscription_billing' => ['subscription_billing', 'Subscription Billing'],
                'system_settings' => ['system_settings', 'System Settings'],
                'audit' => ['audit', 'Audit'],
                default => ['other', ucfirst(str_replace('_', ' ', $permission->module))],
            };
        }

        if ($permission->code === PermissionCodes::PLATFORM_VIEW_FREIGHT
            || $permission->code === PermissionCodes::BRANCH_ACCOUNTING_VIEW_FINANCIAL) {
            return ['financial_visibility', 'Financial Visibility'];
        }

        return match ($permission->module) {
            'platform' => ['common', 'Common Operations'],
            'masters' => ['masters', 'Masters'],
            'enquiry', 'quotation' => ['enquiries', 'Enquiries & Quotations'],
            'lr' => ['documents', 'Lorry Receipts / Documents'],
            'broker' => ['broker', 'Broker'],
            'trip', 'invoice', 'payment' => ['ownfleet', 'OwnFleet'],
            'partload' => ['partload', 'Partload'],
            'accounting' => ['accounting', 'Accounting'],
            'reports' => ['reports', 'Reports'],
            default => ['other', ucfirst(str_replace('_', ' ', $permission->module))],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveResource(Permission $permission): array
    {
        $sub = $permission->submodule;
        $key = $permission->module.($sub ? '.'.$sub : '');

        $labelMap = [
            'platform.operations' => 'General Workspace Actions',
            'masters.general' => 'Operational Masters',
            'enquiry' => 'Enquiries',
            'quotation' => 'Quotations',
            'lr' => 'Unified Bilty / LR',
            'lr.numbering' => 'LR Numbering',
            'trip' => 'OwnFleet Trips',
            'invoice' => 'OwnFleet Invoices',
            'payment' => 'OwnFleet Receipts',
            'broker.lorry_hire' => 'Lorry Hires',
            'broker.voucher' => 'Transporter Vouchers',
            'broker.delivery' => 'Delivery / Memo / POD',
            'broker.adjustment' => 'Freight Adjustments',
            'broker.invoice' => 'Broker Customer Invoices',
            'broker.linked_bilty' => 'Linked Bilties',
            'broker.book_balance' => 'Transporter Book Balance',
            'partload.challan' => 'Partload Challans',
            'partload.inward' => 'Inward Registers',
            'partload.delivery_receipt' => 'Delivery Receipts',
            'accounting.workspace' => 'Accounting Workspace',
            'accounting.voucher' => 'Manual & Automated Vouchers',
            'accounting.report' => 'Accounting Reports',
            'accounting.masters' => 'Ledger & Group Masters',
            'accounting.financial' => 'Financial Statements',
            'reports.general' => 'Operational Reports',
            'crm.leads' => 'CRM Leads & Tickets',
            'internal_users.users' => 'Internal Team Users',
            'customer_onboarding.companies' => 'Customer Companies & Lifecycle',
            'platform_masters.locations' => 'State & City Masters',
            'gst.rates' => 'GST Rate Master',
            'tds.rules' => 'TDS Rule Master',
            'subscription_billing.billing' => 'Billing Profile & Invoices',
            'system_settings.roles' => 'Roles & Permissions',
            'audit.logs' => 'Platform Audit Logs',
        ];

        $label = $labelMap[$key] ?? ucwords(str_replace(['.', '_'], ' ', $key));

        return [$key, $label];
    }

    private function resolveActionKey(string $code): string
    {
        if ($code === PermissionCodes::PLATFORM_VIEW_FREIGHT) {
            return 'view_freight';
        }
        if ($code === PermissionCodes::BRANCH_ACCOUNTING_VIEW_FINANCIAL) {
            return 'view_financial';
        }
        if (str_ends_with($code, '.view')) {
            return 'view';
        }
        if (str_ends_with($code, '.create')) {
            return 'create';
        }
        if (str_ends_with($code, '.edit') || str_ends_with($code, '.update') || str_ends_with($code, '.adjust')) {
            return 'edit';
        }
        if (str_ends_with($code, '.delete')) {
            return 'delete';
        }
        if (str_ends_with($code, '.approve') || str_ends_with($code, '.select') || str_ends_with($code, '.dispatch') || str_ends_with($code, '.complete')) {
            return 'approve';
        }
        if (str_ends_with($code, '.cancel')) {
            return 'cancel';
        }
        if (str_ends_with($code, '.print')) {
            return 'print';
        }
        if (str_ends_with($code, '.export')) {
            return 'export';
        }
        if (str_ends_with($code, '.pay')) {
            return 'settle';
        }
        if (str_ends_with($code, '.reverse')) {
            return 'reverse';
        }
        if (str_contains($code, 'manage') || str_ends_with($code, '.assign') || str_ends_with($code, '.convert') || str_ends_with($code, '.broadcast') || str_ends_with($code, '.status') || str_ends_with($code, '.bulk')) {
            return 'manage';
        }

        return 'other';
    }

    /**
     * @return list<string>
     */
    private function groupOrder(PermissionDomain $domain): array
    {
        if ($domain === PermissionDomain::Internal) {
            return [
                'crm',
                'internal_users',
                'customer_onboarding',
                'platform_masters',
                'gst',
                'tds',
                'subscription_billing',
                'system_settings',
                'audit',
            ];
        }

        return [
            'common',
            'masters',
            'enquiries',
            'documents',
            'broker',
            'ownfleet',
            'partload',
            'accounting',
            'reports',
            'financial_visibility',
        ];
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withInternalContext(callable $callback): mixed
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
}
