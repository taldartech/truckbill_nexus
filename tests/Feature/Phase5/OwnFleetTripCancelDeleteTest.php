<?php

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\TripExpenseService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Models\OwnerCatalogueItem;
use App\Models\Trip;
use App\Models\TripExpense;
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

test('pending and in-progress trips cancel with reason; completed cannot', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $service = app(TripService::class);

    $pending = tripCreatePendingTrip($ctx);
    expect(fn () => $service->cancel($pending, $ctx['member'], ''))
        ->toThrow(ValidationException::class);

    $cancelled = $service->cancel($pending, $ctx['member'], 'Customer withdrew');
    expect($cancelled->status)->toBe(TripStatus::Cancelled)
        ->and($cancelled->cancellation_remark)->toBe('Customer withdrew');

    $trip2 = tripCreatePendingTrip($ctx);
    $inProgress = $service->dispatch($trip2, $ctx['member'], ['starting_odometer' => '10']);
    $service->cancel($inProgress, $ctx['member'], 'Breakdown');

    $trip3 = tripCreatePendingTrip($ctx);
    $dispatched = $service->dispatch($trip3, $ctx['member'], ['starting_odometer' => '10']);
    // force completed without full allocate path for cancel rejection
    $dispatched->forceFill(['status' => TripStatus::Completed])->save();
    expect(fn () => $service->cancel($dispatched->fresh(), $ctx['member'], 'Too late'))
        ->toThrow(TripConflictException::class);
});

test('pending trip soft delete keeps expenses while excluding from active lists', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $category = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Parking',
        'code' => 'PARK',
        'is_active' => true,
    ]);
    app(TripExpenseService::class)->add($trip, $ctx['member'], [
        'expense_category_id' => $category->id,
        'expense_date' => now()->toDateString(),
        'amount' => '250',
    ]);

    app(TripService::class)->softDelete($trip, $ctx['member']);

    expect(Trip::query()->whereKey($trip->id)->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(Trip::withTrashed()->whereKey($trip->id)->exists())->toBeTrue()
        ->and(TripExpense::query()->where('trip_id', $trip->id)->whereNull('deleted_at')->count())->toBe(1)
        ->and(app(TripService::class)->search($ctx['company'], $ctx['branch'])->total())->toBe(0);

    $inProgress = tripCreatePendingTrip($ctx);
    app(TripService::class)->dispatch($inProgress, $ctx['member'], ['starting_odometer' => '1']);
    expect(fn () => app(TripService::class)->softDelete($inProgress->fresh(), $ctx['member']))
        ->toThrow(TripConflictException::class);
});
