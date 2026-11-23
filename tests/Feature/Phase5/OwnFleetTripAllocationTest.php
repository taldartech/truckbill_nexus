<?php

use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Models\TripDropPickupAllocation;
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

test('one pickup can split across multiple drops', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $movements = app(TripMovementService::class);

    $pickup = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '100.000',
        'pickup_date' => now()->toDateString(),
        'goods_description' => 'Coils',
    ]);
    $dropA = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
    ]);
    $dropB = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
    ]);

    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $dropA->id,
        'allocation_weight' => '60',
        'freight' => '600',
    ]);
    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $dropB->id,
        'allocation_weight' => '40',
        'freight' => '400',
    ]);

    $summary = $movements->reconciliationSummary($trip->fresh());
    expect($summary['all_reconciled'])->toBeTrue()
        ->and($summary['total_freight'])->toBe('1000.00')
        ->and($summary['completion_ready'])->toBeTrue();
});

test('multiple pickups can allocate into one drop', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $movements = app(TripMovementService::class);

    $pickupA = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '30',
        'pickup_date' => now()->toDateString(),
    ]);
    $pickupB = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
        'weight' => '20',
        'pickup_date' => now()->toDateString(),
    ]);
    $drop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
    ]);

    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickupA->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '30',
        'freight' => '0',
        'rate' => '0',
    ]);
    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickupB->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '20',
        'freight' => '250',
    ]);

    $summary = $movements->reconciliationSummary($trip->fresh());
    expect($summary['all_reconciled'])->toBeTrue()
        ->and($summary['freight_positive'])->toBeTrue()
        ->and(TripDropPickupAllocation::query()->where('trip_id', $trip->id)->whereNull('deleted_at')->count())->toBe(2);
});

test('over-allocation and zero allocation are rejected while partial remains allowed', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $movements = app(TripMovementService::class);

    $pickup = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '50',
        'pickup_date' => now()->toDateString(),
    ]);
    $drop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
    ]);

    expect(fn () => $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '0',
        'freight' => '10',
    ]))->toThrow(ValidationException::class);

    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '20',
        'freight' => '100',
    ]);

    $secondDrop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
    ]);

    expect(fn () => $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $secondDrop->id,
        'allocation_weight' => '40',
        'freight' => '100',
    ]))->toThrow(ValidationException::class);

    $summary = $movements->reconciliationSummary($trip->fresh());
    expect($summary['all_reconciled'])->toBeFalse()
        ->and($summary['pickups'][0]['remaining'])->toBe('30.000');
});

test('completion reconciliation requires full allocation and positive total freight', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $movements = app(TripMovementService::class);

    $pickup = $movements->addPickup($trip, $ctx['member'], [
        'station_id' => $ctx['fromStation']->id,
        'weight' => '10',
        'pickup_date' => now()->toDateString(),
    ]);
    $drop = $movements->addDrop($trip, $ctx['member'], [
        'station_id' => $ctx['toStation']->id,
    ]);

    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '10',
        'freight' => '0',
    ]);

    expect(fn () => $movements->assertCompletionReconciliation($trip->fresh()))
        ->toThrow(ValidationException::class);

    $movements->upsertAllocation($trip, $ctx['member'], [
        'trip_pickup_id' => $pickup->id,
        'trip_drop_id' => $drop->id,
        'allocation_weight' => '10',
        'freight' => '75',
    ]);

    $movements->assertCompletionReconciliation($trip->fresh());
    expect(true)->toBeTrue();
});
