<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Models\CustomerWebSession;
use App\Models\OwnerCatalogueItem;
use App\Models\TripExpense;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('dedicated trip expenses workspace renders context diesel and profitability props', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.trips.expenses', [$trip]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/expenses')
            ->where('trip.id', $trip->id)
            ->where('trip.lr_number', $trip->lorryReceipt?->lr_number)
            ->has('expenses')
            ->has('profit.diesel_expected_vs_actual_available')
            ->has('profit.total_revenue')
            ->has('formOptions.expense_categories')
            ->has('urls.add_expense')
            ->has('urls.remove_expense'));
});

test('expense store redirects to dedicated expenses workspace and persists diesel variance inputs', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $fuel = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Fuel',
        'code' => 'FUEL-WS',
        'is_active' => true,
    ]);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->post(route('branch.trips.expenses.store', [$trip]), [
        'expense_category_id' => $fuel->id,
        'expense_date' => now('Asia/Kolkata')->toDateString(),
        'is_diesel' => true,
        'diesel_litres' => '40.000',
        'diesel_rate_per_litre' => '95.5000',
        'diesel_odometer' => '12000.0',
        'planned_diesel_quantity' => '38.000',
        'planned_diesel_rate' => '95.5000',
        'remarks' => 'Pump fill',
    ])->assertRedirect(route('branch.trips.expenses', [$trip]));

    $expense = TripExpense::query()
        ->where('trip_id', $trip->id)
        ->whereNull('deleted_at')
        ->firstOrFail();

    expect((bool) $expense->is_diesel)->toBeTrue()
        ->and((string) $expense->diesel_litres)->toStartWith('40')
        ->and((string) $expense->planned_diesel_quantity)->toStartWith('38');

    $this->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.trips.expenses', [$trip]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profit.diesel_expected_vs_actual_available', true)
            ->where('expenses.0.is_diesel', true));
});

test('bilty register manage expenses points at dedicated expenses route', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $expected = route('branch.trips.expenses', [$trip], absolute: false);

    $this->get(route('branch.lorry-receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lorryReceipts.data.0.primary_operation.kind', 'manage_expenses')
            ->where('lorryReceipts.data.0.primary_operation.url', $expected));
});
