<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Models\TripDropPickupAllocation;
use App\Models\TripPickup;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

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

test('vehicle with active trip cannot create a second trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripCreatePendingTrip($ctx);

    $secondLr = tripCreateOwnFleetLr($ctx);

    expect(fn () => app(TripService::class)->createFromLorryReceipt($secondLr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]))->toThrow(ValidationException::class);
});

test('pickup can be removed with cascading allocations while pending', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $movements = app(TripMovementService::class);

    $pickup = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '10.000',
        'pickup_date' => now()->toDateString(),
    ]);
    $drop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
    ]);
    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '10.000',
        'rate' => '100',
        'freight' => '1000.00',
    ]);

    $movements->removePickup($trip, $ctx['member'], $pickup);

    expect(TripPickup::query()->whereKey($pickup->id)->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(TripDropPickupAllocation::query()->where('trip_pickup_id', $pickup->id)->whereNull('deleted_at')->count())->toBe(0);
});

test('http destroy pickup removes row', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $pickup = app(TripMovementService::class)->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '5.000',
        'pickup_date' => now()->toDateString(),
    ]);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->delete(route('branch.trips.pickups.destroy', [$trip, $pickup]))
        ->assertRedirect();

    expect(TripPickup::query()->whereKey($pickup->id)->whereNull('deleted_at')->exists())->toBeFalse();
});
