<?php

namespace App\Http\Middleware;

use App\Support\Cache\RequestScopedMemo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activates the request-scoped memo for the request and discards it after the response,
 * including when the request is aborted.
 */
final class ScopeRequestMemo
{
    public function __construct(
        private readonly RequestScopedMemo $memo,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->memo->activate();

        try {
            return $next($request);
        } finally {
            $this->memo->deactivate();
        }
    }
}
