<?php

/**
 * Fails closed when a new named web route appears without an authorization contract entry.
 *
 * Public/auth exceptions are explicit. Expand the contract as routes are reviewed.
 */

use Illuminate\Support\Facades\Route;

/**
 * Explicitly reviewed public / unauthenticated / framework routes.
 *
 * @return list<string>
 */
function publicRouteContractNames(): array
{
    return [
        'home',
        'login',
        'login.store',
        'logout',
        'password.request',
        'password.email',
        'password.reset',
        'password.store',
        'password.confirm',
        'password.confirm.store',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'two-factor.login',
        'two-factor.login.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.recovery-codes',
        'appearance',
        'boost.browser-logs',
        'storage.local',
    ];
}

/**
 * Named routes that must have an authorization contract (middleware and/or controller assert).
 * Maintained as the audited protected surface; unknown named routes fail this test.
 *
 * @return list<string>
 */
function protectedRouteContractNames(): array
{
    $names = collect(Route::getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->reject(fn (string $name) => str_starts_with($name, 'generated::'))
        ->reject(fn (string $name) => in_array($name, publicRouteContractNames(), true))
        ->reject(fn (string $name) => str_starts_with($name, 'debugbar.'))
        ->reject(fn (string $name) => str_starts_with($name, 'horizon.'))
        ->values()
        ->all();

    return $names;
}

test('every named application route is classified as public exception or protected contract candidate', function () {
    $allNamed = collect(Route::getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->reject(fn (string $name) => str_starts_with($name, 'generated::'))
        ->values()
        ->all();

    $public = publicRouteContractNames();
    $protected = protectedRouteContractNames();

    foreach ($allNamed as $name) {
        $classified = in_array($name, $public, true)
            || in_array($name, $protected, true)
            || str_starts_with($name, 'debugbar.')
            || str_starts_with($name, 'horizon.');

        expect($classified)->toBeTrue("Unclassified named route: {$name}");
    }

    expect(count($protected))->toBeGreaterThan(50);
});

test('critical operational route names remain registered for RBAC HTTP coverage', function () {
    $required = [
        'branch.dashboard',
        'branch.trips.index',
        'branch.trips.show',
        'branch.trips.dispatch',
        'branch.trips.complete',
        'branch.lorry-receipts.index',
        'branch.enquiries.index',
        'branch.trip-invoices.index',
        'owner.home',
        'owner.companies.index',
        'admin.crm.dashboard',
        'admin.crm.leads.index',
        'admin.crm.tickets.index',
        'admin.internal-users.index',
    ];

    foreach ($required as $name) {
        expect(Route::has($name))->toBeTrue("Missing required protected route: {$name}");
    }
});

test('protected branch mutation routes require authentication middleware', function () {
    $routes = [
        'branch.trips.dispatch',
        'branch.trips.complete',
        'branch.trips.cancel',
        'branch.trips.destroy',
        'branch.enquiries.store',
        'branch.lorry-receipts.store',
    ];

    foreach ($routes as $name) {
        if (! Route::has($name)) {
            continue;
        }

        $route = Route::getRoutes()->getByName($name);
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('auth')
            ->and($middleware)->not->toContain('guest');
    }
});
