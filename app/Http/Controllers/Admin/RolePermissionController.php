<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Rbac\RolePermissionManagementService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Centralized Admin/CRM Roles & Permissions Management controller.
 */
class RolePermissionController extends Controller
{
    public function __construct(
        private readonly RolePermissionManagementService $service = new RolePermissionManagementService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->internalAuth->authorizeAnyOrAbort($request, [
            PermissionCodes::INTERNAL_SETTINGS_VIEW,
            PermissionCodes::INTERNAL_SETTINGS_MANAGE,
        ]);

        $register = $this->service->registerSnapshot();

        return Inertia::render('admin/roles/index', [
            'internalRoles' => $register['internal_roles'],
            'customerRoles' => $register['customer_roles'],
            'auditHistory' => $register['audit_history'],
            'canManage' => $this->internalAuth->allows($actor, PermissionCodes::INTERNAL_SETTINGS_MANAGE),
            'links' => [
                'store' => route('admin.roles.store'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SETTINGS_MANAGE);

        $validated = $request->validate([
            'domain' => ['required', Rule::enum(PermissionDomain::class)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $role = $this->service->createRole($actor, $validated);
        } catch (RbacAuthorizationException|RbacDomainException|InvalidArgumentException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('success', 'Role created. Configure its permissions below.');
    }

    public function edit(Request $request, Role $role): Response
    {
        $actor = $this->internalAuth->authorizeAnyOrAbort($request, [
            PermissionCodes::INTERNAL_SETTINGS_VIEW,
            PermissionCodes::INTERNAL_SETTINGS_MANAGE,
        ]);

        $matrix = $this->service->matrixForRole($actor, $role);
        $auditHistory = $this->service->roleAuditHistory($role);

        return Inertia::render('admin/roles/edit', [
            'role' => [
                'id' => $role->id,
                'domain' => $role->domain->value,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_system' => (bool) $role->is_system,
                'is_active' => $role->isActive(),
            ],
            'matrix' => $matrix,
            'auditHistory' => $auditHistory,
            'canManage' => $this->internalAuth->allows($actor, PermissionCodes::INTERNAL_SETTINGS_MANAGE),
            'links' => [
                'index' => route('admin.roles.index'),
                'update' => route('admin.roles.update', $role),
                'sync_permissions' => route('admin.roles.permissions.sync', $role),
                'toggle' => route('admin.roles.toggle', $role),
            ],
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SETTINGS_MANAGE);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->service->updateRoleDetails($actor, $role, $validated, $validated['remark']);
        } catch (RbacAuthorizationException|RbacDomainException|InvalidArgumentException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('success', 'Role details updated.');
    }

    public function syncPermissions(Request $request, Role $role): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SETTINGS_MANAGE);

        $validated = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['uuid'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $diff = $this->service->syncRolePermissions(
                $actor,
                $role,
                $validated['permission_ids'] ?? [],
                $validated['remark'],
            );
        } catch (RbacAuthorizationException|RbacDomainException|InvalidArgumentException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        return back()->with(
            'success',
            sprintf(
                'Permissions updated (%d added, %d removed). Permission cache invalidated immediately.',
                count($diff['added']),
                count($diff['removed']),
            ),
        );
    }

    public function toggle(Request $request, Role $role): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SETTINGS_MANAGE);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->service->toggleRoleStatus($actor, $role, $validated['remark']);
        } catch (RbacAuthorizationException|RbacDomainException|InvalidArgumentException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('success', $updated->isActive() ? 'Role activated.' : 'Role deactivated.');
    }
}
