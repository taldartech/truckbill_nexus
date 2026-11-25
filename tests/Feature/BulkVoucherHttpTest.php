<?php

use App\Domains\Auth\WebSessionService;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantVoucherPermissions();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('bulk voucher create page renders for authorized operators', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    brokerCreateLorryHire($ctx);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.broker.vouchers.bulk.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/vouchers/bulk')
            ->has('eligibleLorryHires')
            ->has('urls.bulk_preview')
            ->has('urls.bulk_store')
            ->where('permissions.can_bulk', true));
});

test('voucher index exposes bulk payment url when permitted', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $this->flushSession();
    $this->actingAs($ctx['member']);
    session()->forget(WebSessionService::SESSION_VERSION_KEY);
    seedBranchSession($ctx['company'], $ctx['branch']);

    $this->get(route('branch.broker.vouchers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/vouchers/index')
            ->where('permissions.can_bulk', true)
            ->where(
                'urls.bulk',
                route('branch.broker.vouchers.bulk.create', absolute: false),
            )
            ->where(
                'urls.export_payout',
                route('branch.broker.vouchers.export-payout', absolute: false),
            ));
});
