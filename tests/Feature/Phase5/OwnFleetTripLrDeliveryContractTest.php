<?php

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Models\Trip;
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

/**
 * Contract: OwnFleet invoice eligibility follows completed Trip + OwnFleet LR + billing customer.
 * Trip completion must not auto-mark the LR Delivered (delivery remains a separate LR lifecycle transition).
 */
test('completing trip does not deliver lr and invoice uses completed trip not lr delivered status', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);

    expect($lr->status)->toBe(LrStatus::Pending)
        ->and($lr->delivered_at)->toBeNull();

    $completed = tripCompleteTrip($ctx, $trip->fresh());
    $lrAfterTrip = $lr->fresh();

    expect($completed->status)->toBe(TripStatus::Completed)
        ->and($lrAfterTrip->status)->toBe(LrStatus::Pending)
        ->and($lrAfterTrip->delivered_at)->toBeNull()
        ->and($lrAfterTrip->workflow_context)->toBe(LrWorkflowContext::OwnFleet);

    $invoice = app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lrAfterTrip->id,
        ['number_mode' => 'manual', 'manual_number' => 'LR-DEL-CONTRACT-1'],
    );

    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->trip_id)->toBe($completed->id);
});

test('delivered lr without completed trip remains ineligible for ownfleet trip invoice', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);

    $lr->forceFill([
        'status' => LrStatus::Delivered,
        'delivered_at' => now(),
    ])->save();

    expect(Trip::query()->whereKey($trip->id)->firstOrFail()->status)->toBe(TripStatus::Pending);

    expect(fn () => app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lr->fresh()->id,
        ['number_mode' => 'manual', 'manual_number' => 'LR-DEL-CONTRACT-2'],
    ))->toThrow(TripInvoiceConflictException::class);
});

test('invoice eligible list includes completed trip lr while lr status is still pending', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $eligible = app(TripInvoiceService::class)->listInvoiceEligibleLorryReceipts($ctx['company'], $ctx['branch']);

    expect($eligible->pluck('id'))->toContain($lr->fresh()->id)
        ->and($lr->fresh()->status)->toBe(LrStatus::Pending);
});
