<?php

use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Enums\TripTimingPerformance;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Domains\OwnFleet\Trip\TripService;
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

function tripFullyAllocate(array $ctx, $trip): void
{
    $movements = app(TripMovementService::class);
    $pickup = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '100',
        'pickup_date' => now()->toDateString(),
    ]);
    $drop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
    ]);
    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '100',
        'freight' => '1500',
    ]);
}

test('dispatch requires starting odometer and rejects duplicate dispatch', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $service = app(TripService::class);

    expect(fn () => $service->dispatch($trip, $ctx['member'], []))
        ->toThrow(ValidationException::class);

    $dispatched = $service->dispatch($trip, $ctx['member'], [
        'starting_odometer' => '1200.5',
    ]);

    expect($dispatched->status)->toBe(TripStatus::InProgress)
        ->and($dispatched->starting_odometer)->toBe('1200.5')
        ->and($dispatched->departure_at)->not->toBeNull();

    expect(fn () => $service->dispatch($dispatched, $ctx['member'], [
        'starting_odometer' => '1201',
    ]))->toThrow(TripConflictException::class);
});

test('completion validates odometer distance allocation and timing classification', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $service = app(TripService::class);

    $dispatched = $service->dispatch($trip, $ctx['member'], [
        'starting_odometer' => '1000',
        'departure_at' => '2026-11-23 08:00:00',
    ]);

    expect(fn () => $service->complete($dispatched, $ctx['member'], [
        'ending_odometer' => '1100',
    ]))->toThrow(ValidationException::class);

    tripFullyAllocate($ctx, $dispatched);

    expect(fn () => $service->complete($dispatched->fresh(), $ctx['member'], [
        'ending_odometer' => '900',
    ]))->toThrow(ValidationException::class);

    $withExpected = $dispatched->fresh();
    $withExpected->forceFill([
        'expected_end_at' => '2026-11-23 18:00:00',
    ])->save();

    $completed = $service->complete($withExpected->fresh(), $ctx['member'], [
        'ending_odometer' => '1125.5',
        'actual_arrival_at' => '2026-11-23 19:30:00',
    ]);

    expect($completed->status)->toBe(TripStatus::Completed)
        ->and($completed->trip_distance)->toBe('125.5')
        ->and($completed->timing_performance)->toBe(TripTimingPerformance::Delayed);

    expect(fn () => $service->complete($completed, $ctx['member'], [
        'ending_odometer' => '1200',
    ]))->toThrow(TripConflictException::class);
});
