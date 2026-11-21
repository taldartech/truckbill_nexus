<?php

namespace App\Http\Middleware;

use App\Support\Tenant\TenantContextHolder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ensures tenant context is request-scoped: cleared at start and after the response.
 * Trusted injection during the request uses TenantContextHolder::inject().
 */
class ManageTenantContextLifecycle
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        TenantContextHolder::clear();

        try {
            return $next($request);
        } catch (Throwable $e) {
            throw $e;
        } finally {
            TenantContextHolder::clear();
        }
    }
}
