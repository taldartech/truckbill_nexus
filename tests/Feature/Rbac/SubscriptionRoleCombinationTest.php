<?php

/**
 * Subscription entitlement × Customer role combinations for OwnFleet trips.
 */

use App\Domains\Auth\WebSessionService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\CustomerWebSession;
use App\Models\Trip;
use App\Models\User;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

function subHttpAs(User $user, array $ctx): void
{
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $user->id)->delete();
    test()->actingAs($user);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);
}

test('ownfleet admin with trip.create can open trips when OwnFleet is subscribed', function () {
    $ctx = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    tripActivate($ctx['company'], $ctx['branch']);
    tripCreatePendingTrip($ctx);

    expect(app(PermissionAuthorizer::class)->allowsCustomer(
        $ctx['member'],
        PermissionCodes::TRIP_CREATE,
        $ctx['company'],
        $ctx['branch'],
    ))->toBeTrue();

    subHttpAs($ctx['member'], $ctx);
    // Standalone Trips index redirects to the Bilty Register (primary OwnFleet entry).
    test()->get(route('branch.trips.index'))
        ->assertRedirect(route('branch.lorry-receipts.index'));
});

test('broker-only workspace denies OwnFleet trip index even with trip.view grant', function () {
    $ctx = tripWorkspace([SubscriptionModule::Broker->value]);
    // Grant trip.view on admin (already via tripWorkspace grants) but module absent.
    tripGrant('customer.admin', PermissionCodes::TRIP_VIEW);

    tripActivate($ctx['company'], $ctx['branch']);
    subHttpAs($ctx['member'], $ctx);

    test()->get(route('branch.trips.index'))->assertForbidden();
});

test('viewer with trip.view cannot dispatch even when OwnFleet is active', function () {
    $ctx = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    expect(app(PermissionAuthorizer::class)->allowsCustomer(
        $ctx['viewer'],
        PermissionCodes::TRIP_DISPATCH,
        $ctx['company'],
        $ctx['branch'],
    ))->toBeFalse();

    subHttpAs($ctx['viewer'], $ctx);
    $before = $trip->fresh()->status;

    $response = test()->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('branch.trips.dispatch', $trip), [
            'starting_odometer' => 100,
        ]);

    expect(in_array($response->status(), [403, 302], true))->toBeTrue()
        ->and($trip->fresh()->status)->toBe($before)
        ->and(Trip::query()->whereKey($trip->id)->exists())->toBeTrue();
});
