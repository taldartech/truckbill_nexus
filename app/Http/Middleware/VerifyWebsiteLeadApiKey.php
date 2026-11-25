<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server auth for website → Nexus public lead capture (X-Api-Key).
 */
class VerifyWebsiteLeadApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('public_leads.api_key', '');
        if ($configured === '') {
            return response()->json(['message' => 'Public lead capture is not configured.'], 503);
        }

        $provided = (string) $request->header('X-Api-Key', '');
        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
