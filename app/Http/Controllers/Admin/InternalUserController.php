<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\Enums\PermissionDomain;
use App\Domains\Rbac\Exceptions\RbacAuthorizationException;
use App\Domains\Rbac\Exceptions\RbacDomainException;
use App\Domains\Rbac\RoleAssignmentService;
use App\Http\Controllers\Controller;
use App\Models\InternalRoleAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin Internal Team user administration (Users module).
 * Customer identities are managed under Customer identities / company lifecycle — not here.
 */
class InternalUserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertSuperAdmin($request);

        $search = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $roleFilter = trim($request->string('role')->toString());
        if ($status === '') {
            $status = 'all';
        }
        if ($roleFilter === '') {
            $roleFilter = 'all';
        }

        $query = User::query()
            ->internal()
            ->orderByDesc('is_super_admin')
            ->orderBy('email');

        if ($search !== '') {
            CaseInsensitiveLike::whereAnyContains($query, ['name', 'email'], $search);
        }

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'inactive' || $status === 'deactivated') {
            $query->deactivated();
        }

        if ($roleFilter === 'super_admin') {
            $query->where('is_super_admin', true);
        } elseif ($roleFilter !== 'all') {
            $query->where('is_super_admin', false)
                ->whereIn('id', InternalRoleAssignment::query()
                    ->whereNull('deactivated_at')
                    ->where('role_id', $roleFilter)
                    ->select('user_id'));
        }

        $users = $query->paginate(25)->withQueryString();

        $roleNamesByUserId = $this->activeRoleNamesForUsers(
            $users->getCollection()->map(fn (User $user): int => (int) $user->id)->all()
        );

        $roles = Role::query()
            ->where('domain', PermissionDomain::Internal)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return Inertia::render('admin/internal-users/index', [
            'users' => $users->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_name' => $user->is_super_admin
                    ? 'Super Admin'
                    : ($roleNamesByUserId[$user->id] ?? '—'),
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
            'filters' => [
                'q' => $search,
                'status' => $status,
                'role' => $roleFilter,
            ],
            'store_url' => route('admin.internal-users.store'),
            'urls' => [
                'index' => route('admin.internal-users.index'),
            ],
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'per_page' => $users->perPage(),
            ],
            'note' => 'Internal Team users only. Customer Owners and Customer Users are managed under Customer identities and company administration.',
        ]);
    }

    public function store(Request $request, RoleAssignmentService $roles): RedirectResponse
    {
        $actor = $this->assertSuperAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['nullable', 'uuid', 'exists:roles,id'],
            'is_super_admin' => ['sometimes', 'boolean'],
        ]);

        $isSuperAdmin = (bool) ($validated['is_super_admin'] ?? false);
        $role = null;

        if (! $isSuperAdmin) {
            if (! isset($validated['role_id'])) {
                throw ValidationException::withMessages([
                    'role_id' => 'Select an Internal Team role, or grant Super Admin.',
                ]);
            }
            /** @var Role $role */
            $role = Role::query()->whereKey($validated['role_id'])->firstOrFail();
            if ($role->domain !== PermissionDomain::Internal) {
                throw ValidationException::withMessages([
                    'role_id' => 'Only approved Internal Team roles may be assigned here.',
                ]);
            }
        }

        try {
            $roles->createInternalUser($actor, [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_super_admin' => $isSuperAdmin,
            ], $isSuperAdmin ? null : $role);
        } catch (RbacAuthorizationException|RbacDomainException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.internal-users.index')
            ->with('success', 'Internal Team user created.');
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, string>
     */
    private function activeRoleNamesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $assignments = InternalRoleAssignment::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('deactivated_at')
            ->with('role:id,name')
            ->get();

        $map = [];
        foreach ($assignments as $assignment) {
            $name = $assignment->role?->name;
            if ($name === null) {
                continue;
            }
            $userId = $assignment->user_id;
            $map[$userId] = isset($map[$userId])
                ? $map[$userId].', '.$name
                : $name;
        }

        return $map;
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
