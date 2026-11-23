<?php

use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\OwnFleet\Trip\TripExpenseService;
use App\Domains\OwnFleet\Trip\TripService;
use App\Models\OwnerCatalogueItem;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
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

test('diesel amount is litres times rate and profit excludes gst using lr freight', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $fuel = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Fuel',
        'code' => 'FUEL',
        'is_active' => true,
    ]);
    $toll = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Toll',
        'code' => 'TOLL',
        'is_active' => true,
    ]);

    $expenses = app(TripExpenseService::class);
    $diesel = $expenses->add($trip, $ctx['member'], [
        'expense_category_id' => $fuel->id,
        'expense_date' => now()->toDateString(),
        'is_diesel' => true,
        'diesel_litres' => '40',
        'diesel_rate_per_litre' => '95.5',
        'diesel_odometer' => '1000',
    ]);
    $expenses->add($trip, $ctx['member'], [
        'expense_category_id' => $toll->id,
        'expense_date' => now()->toDateString(),
        'amount' => '500',
    ]);

    expect($diesel->amount)->toBe('3820.00');

    $summary = $expenses->profitSummary($trip->fresh(['lorryReceipt']));
    // LR freight 12000 + charges 0 - diesel 3820 - toll 500 = 7680
    expect($summary['agreed_freight'])->toBe('12000.00')
        ->and($summary['diesel_cost'])->toBe('3820.00')
        ->and($summary['other_expenses'])->toBe('500.00')
        ->and($summary['trip_profit'])->toBe('7680.00')
        ->and($summary['trip_mileage_label'])->toBe('Not Available');

    $dispatched = app(TripService::class)->dispatch($trip, $ctx['member'], [
        'starting_odometer' => '1000',
    ]);
    // without completion mileage stays N/A
    expect(app(TripExpenseService::class)->profitSummary($dispatched->fresh(['lorryReceipt']))['trip_mileage_label'])
        ->toBe('Not Available');
});
