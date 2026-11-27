<?php

namespace App\Domains\Rbac;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Shared Internal Team authorization helper using existing PermissionAuthorizer.
 * Super Admin always holds unrestricted internal access; ordinary Internal users
 * require explicit active permission grant via their Internal role.
 */
final class InternalAuthorization
{
    public function __construct(
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
    ) {}

    public function allows(User $user, string $permissionCode): bool
    {
        if (! $user->isInternal() || ! $user->isActive()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->authorizer->allowsInternal($user, $permissionCode);
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    public function allowsAny(User $user, array $permissionCodes): bool
    {
        if (! $user->isInternal() || ! $user->isActive()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach ($permissionCodes as $code) {
            if ($this->authorizer->allowsInternal($user, $code)) {
                return true;
            }
        }

        return false;
    }

    public function authorizeOrAbort(Request $request, string $permissionCode): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->allows($actor, $permissionCode)) {
            abort(403, 'You are not authorized for this internal operation.');
        }

        return $actor;
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    public function authorizeAnyOrAbort(Request $request, array $permissionCodes): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isInternal() || ! $actor->isActive()) {
            abort(403, 'Internal authentication required.');
        }

        if ($actor->isSuperAdmin()) {
            return $actor;
        }

        foreach ($permissionCodes as $code) {
            if ($this->authorizer->allowsInternal($actor, $code)) {
                return $actor;
            }
        }

        abort(403, 'You are not authorized for this internal operation.');
    }
}
