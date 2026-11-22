<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceWebSessionPolicy;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ManageTenantContextLifecycle;
use App\Http\Middleware\RejectClientTenantIdentifiers;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Razorpay Test Mode webhooks authenticate via HMAC, not session CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/razorpay',
        ]);

        // Global: Lifecycle must wrap Reject so context is cleared even on abort.
        // Order: ManageTenantContextLifecycle (outer) → RejectClientTenantIdentifiers (inner).
        $middleware->append([
            AssignCorrelationId::class,
            ManageTenantContextLifecycle::class,
            RejectClientTenantIdentifiers::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnforceWebSessionPolicy::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
