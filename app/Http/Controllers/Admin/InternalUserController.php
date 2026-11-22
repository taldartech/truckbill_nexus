<?php

namespace App\Http\Controllers\Admin;

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

class InternalUserController extends Controller
{
    public function store(Request $request, RoleAssignmentService $roles): RedirectResponse
    {
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

        return back();
    }
}
