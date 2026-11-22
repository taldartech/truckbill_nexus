<?php

namespace App\Http\Middleware;

use App\Domains\Auth\WebSessionService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Server-side web session policy (P2-WS-4 + driver-safe follow-up).
 *
 * All identities: web_session_version stamp must match (driver-agnostic revocation).
 * Customers: active-session registry must also match.
 * Guests pass through. Inactive identities are expired.
 */
final class EnforceWebSessionPolicy
{
    public function __construct(
        private readonly WebSessionService $webSessions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Reload lifecycle/version attributes — Auth may hold a stale in-memory model.
        $user->refresh();

        if (! $user->isActive()) {
            return $this->expire($request);
        }

        try {
            if (! $this->webSessions->isSessionVersionValid($user)) {
                // Testing-only: actingAs without Fortify login has no stamp yet.
                // Never bootstrap when a stamp already exists (stale after revocation).
                if (config('auth.customer_session_test_claim')
                    && ! $request->session()->has(WebSessionService::SESSION_VERSION_KEY)) {
                    $this->webSessions->stampSessionVersion($user);
                } else {
                    return $this->expire($request);
                }
            }

            if ($user->isInternal()) {
                return $next($request);
            }

            if ($user->isCustomer()) {
                $sessionId = $request->session()->getId();

                if ($this->webSessions->isCustomerSessionValid($user, $sessionId)) {
                    return $next($request);
                }

                if (config('auth.customer_session_test_claim')
                    && $this->webSessions->claimMissingCustomerSession($user, $sessionId)) {
                    return $next($request);
                }

                return $this->expire($request);
            }
        } catch (Throwable $e) {
            Log::warning('web_session_policy_enforcement_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->expire($request);
        }

        return $this->expire($request);
    }

    private function expire(Request $request): Response
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
        }

        return redirect()->guest(route('login'));
    }
}
