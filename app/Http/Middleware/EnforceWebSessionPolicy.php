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
 * Server-side web session policy (P2-WS-4).
 *
 * Guests and Internal concurrent sessions pass through.
 * Customer requests fail closed when the active-session registry does not match.
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

        if (! $user->isActive()) {
            return $this->expire($request);
        }

        if ($user->isInternal()) {
            return $next($request);
        }

        if ($user->isCustomer()) {
            $sessionId = $request->session()->getId();

            try {
                if ($this->webSessions->isCustomerSessionValid($user, $sessionId)) {
                    return $next($request);
                }

                // Testing-only: actingAs authenticates without the Fortify login pipeline.
                // Production always fail-closes when the registry is missing or mismatched.
                if (config('auth.customer_session_test_claim')
                    && $this->webSessions->claimMissingCustomerSession($user, $sessionId)) {
                    return $next($request);
                }
            } catch (Throwable $e) {
                Log::warning('customer_session_policy_enforcement_failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->expire($request);
        }

        return $next($request);
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
