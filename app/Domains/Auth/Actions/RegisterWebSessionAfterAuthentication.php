<?php

namespace App\Domains\Auth\Actions;

use App\Domains\Auth\WebSessionService;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Runs after Fortify's PrepareAuthenticatedSession so the registered
 * Customer session id matches the post-login regenerated session.
 */
final class RegisterWebSessionAfterAuthentication
{
    public function __construct(
        private readonly WebSessionService $webSessions,
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->webSessions->registerAuthenticatedSession($user, $request->session()->getId());
        }

        return $next($request);
    }
}
