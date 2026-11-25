<?php

use App\Domains\Auth\WebSessionService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('bilty register exposes manage expenses for own vehicle without trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    // Service-level OwnFleet create does not auto-attach a Trip; primary action stays hidden.
    $lr = tripCreateOwnFleetLr($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.lorry-receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/lorry-receipts/index')
            ->where('lorryReceipts.data.0.id', $lr->id)
            ->where('lorryReceipts.data.0.vehicle_ownership', VehicleOwnership::Own->value)
            ->where('lorryReceipts.data.0.primary_operation', null));
});

test('bilty register exposes manage expenses for existing trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.lorry-receipts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lorryReceipts.data.0.primary_operation.kind', 'manage_expenses')
            ->where('lorryReceipts.data.0.primary_operation.label', 'Manage Expenses')
            ->where('lorryReceipts.data.0.primary_operation.url', fn ($url) => str_contains((string) $url, (string) $trip->id)));
});

test('standalone trips index redirects to bilty register', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripCreatePendingTrip($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.trips.index'))
        ->assertRedirect(route('branch.lorry-receipts.index'));
});

test('historical trip show remains available', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.trips.show', [$trip]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/show')
            ->has('urls.lr_show')
            ->has('urls.multi_point')
            ->has('urls.expenses'));
});

test('multi-point workspace is available for ownfleet trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.trips.multi-point', [$trip]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('branch/trips/multi-point')
            ->where('trip.id', $trip->id)
            ->has('movements.pickups')
            ->has('urls.add_pickup')
            ->has('urls.expenses'));
});

test('bilty register ownership filters narrow ownfleet awaiting trip rows', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = tripCreateOwnFleetLr($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.lorry-receipts.index', [
        'ownership' => 'own',
        'awaiting_trip' => '1',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.ownership', 'own')
            ->where('filters.awaiting_trip', '1')
            ->where('lorryReceipts.data.0.id', $lr->id)
            ->where('lorryReceipts.data.0.primary_operation', null));
});
