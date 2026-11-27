<?php

use App\Domains\Auth\PostLoginLandingResolver;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceWebSessionPolicy;
use App\Http\Middleware\EnsureCompanyCapability;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ManageTenantContextLifecycle;
use App\Http\Middleware\RejectClientTenantIdentifiers;
use App\Http\Middleware\ResolveBranchWorkspace;
use App\Http\Middleware\ResolveManagementCompany;
use App\Http\Middleware\VerifyWebsiteLeadApiKey;
use App\Models\User;
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

        // External webhooks authenticate via their own secrets, not session CSRF.
        // Only these exact paths are exempt — never broaden this list casually.
        $middleware->validateCsrfTokens(except: [
            'webhooks/razorpay',
            'webhooks/enquiries',
            'api/v1/public/onboarding/*',
            'api/v1/public/leads',
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            return $user instanceof User
                ? app(PostLoginLandingResolver::class)->resolve($request, $user, consumeIntended: false)
                : '/workspace';
        });

        $middleware->alias([
            'branch.workspace' => ResolveBranchWorkspace::class,
            'company.capability' => EnsureCompanyCapability::class,
            'management.company' => ResolveManagementCompany::class,
            'website.lead.key' => VerifyWebsiteLeadApiKey::class,
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
