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
            ->where('lorryReceipts.data.0.primary_operation.kind', 'init_trip')
            ->where('lorryReceipts.data.0.primary_operation.label', 'Manage Expenses'));
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
            ->has('urls.lr_show'));
});
