<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Never trust client-supplied company/branch/owner identifiers (TB-SEC-002).
 */
class RejectClientTenantIdentifiers
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (['company_id', 'branch_id', 'owner_id'] as $key) {
            if ($request->query->has($key) || $request->request->has($key)) {
                abort(403, 'Client-supplied tenant identifiers are not permitted.');
            }
        }

        foreach (['X-Company-Id', 'X-Branch-Id', 'X-Owner-Id', 'X-Tenant-Id'] as $header) {
            if ($request->headers->has($header)) {
                abort(403, 'Client-supplied tenant identifiers are not permitted.');
            }
        }

        return $next($request);
    }
}
