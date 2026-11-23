<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\RoleAssignmentService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin Internal user administration (non-CRM).
 */
class InternalUserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertSuperAdmin($request);

        $users = User::query()
            ->internal()
            ->orderByDesc('is_super_admin')
            ->orderBy('email')
            ->limit(200)
            ->get();

        $roles = Role::query()
            ->where('domain', PermissionDomain::Internal)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return Inertia::render('admin/internal-users/index', [
            'users' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->is_super_admin,
                'is_active' => $user->isActive(),
                'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                'show_url' => route('admin.identities.show', $user),
            ])->values()->all(),
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->values()->all(),
            'store_url' => route('admin.internal-users.store'),
            'note' => 'Internal user management only. Sales CRM is a separate Phase 2A surface.',
        ]);
    }

    public function store(Request $request, RoleAssignmentService $roles): RedirectResponse
    {
        $this->assertSuperAdmin($request);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['nullable', 'uuid', 'exists:roles,id'],
            'is_super_admin' => ['sometimes', 'boolean'],
        ]);

        $role = null;
        if (isset($validated['role_id'])) {
            /** @var Role $role */
            $role = Role::query()->whereKey($validated['role_id'])->firstOrFail();
        }

        try {
            $roles->createInternalUser($actor, [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_super_admin' => (bool) ($validated['is_super_admin'] ?? false),
            ], $role);
        } catch (RbacAuthorizationException|RbacDomainException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.internal-users.index')
            ->with('success', 'Internal user created.');
    }

    private function assertSuperAdmin(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isSuperAdmin() || ! $actor->isActive()) {
            abort(403, 'Only an active Super Admin may manage Internal users.');
        }

        return $actor;
    }
}
