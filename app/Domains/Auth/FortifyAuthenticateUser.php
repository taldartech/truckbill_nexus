<?php

namespace App\Domains\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;

/**
 * Fortify credential verification with identity lifecycle checks (P2-WS-4).
 *
 * Returns null for any ineligible identity — generic failure, no identity-type leakage.
 */
final class FortifyAuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $email = $request->input(Fortify::username());

        if (! is_string($email) || $email === '') {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()->where(Fortify::username(), $email)->first();

        if ($user === null) {
            return null;
        }

        if ($user->identity_type === null || ! $user->isActive()) {
            return null;
        }

        $password = $request->input('password');

        if (! is_string($password) || ! Hash::check($password, $user->getAuthPassword())) {
            return null;
        }

        return $user;
    }
}
