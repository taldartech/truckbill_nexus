<?php

use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Enums\TripTimingPerformance;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\TripMovementService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
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

    // Operational datetimes are company-local wall clocks; persistence is UTC.
    $timezone = filled($ctx['company']->timezone) ? (string) $ctx['company']->timezone : 'Asia/Kolkata';
    $withExpected = $dispatched->fresh();
    $withExpected->forceFill([
        'expected_end_at' => CarbonImmutable::parse('2026-11-23 18:00:00', $timezone)->utc(),
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

test('timing classification covers early on-time delayed not-applicable and midnight boundary', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $service = app(TripService::class);
    $timezone = filled($ctx['company']->timezone) ? (string) $ctx['company']->timezone : 'Asia/Kolkata';

    $cases = [
        ['expected' => '2026-11-23 18:00:00', 'arrival' => '2026-11-23 17:30:00', 'result' => TripTimingPerformance::Early],
        ['expected' => '2026-11-23 18:00:00', 'arrival' => '2026-11-23 18:00:00', 'result' => TripTimingPerformance::OnTime],
        ['expected' => '2026-11-23 18:00:00', 'arrival' => '2026-11-23 18:00:45', 'result' => TripTimingPerformance::OnTime],
        ['expected' => '2026-11-23 18:00:00', 'arrival' => '2026-11-23 19:30:00', 'result' => TripTimingPerformance::Delayed],
        ['expected' => '2026-11-23 23:30:00', 'arrival' => '2026-11-24 00:15:00', 'result' => TripTimingPerformance::Delayed],
        ['expected' => null, 'arrival' => '2026-11-23 19:30:00', 'result' => TripTimingPerformance::NotApplicable],
    ];

    foreach ($cases as $index => $case) {
        $trip = tripCreatePendingTrip($ctx);
        $dispatched = $service->dispatch($trip, $ctx['member'], [
            'starting_odometer' => (string) (1000 + ($index * 10)),
            'departure_at' => '2026-11-23 08:00:00',
        ]);
        tripFullyAllocate($ctx, $dispatched);

        if ($case['expected'] !== null) {
            $dispatched->forceFill([
                'expected_end_at' => CarbonImmutable::parse($case['expected'], $timezone)->utc(),
            ])->save();
        }

        $completed = $service->complete($dispatched->fresh(), $ctx['member'], [
            'ending_odometer' => (string) (1100 + ($index * 10)),
            'actual_arrival_at' => $case['arrival'],
        ]);

        expect($completed->timing_performance)->toBe($case['result']);
    }
});
