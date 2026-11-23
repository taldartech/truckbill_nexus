<?php

use App\Domains\OwnFleet\Invoice\Enums\TripInvoiceStatus;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Models\Party;
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

test('individual invoice captures gst snapshot and manual numbering', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);

    $customer = tripCreateBillingCustomer($ctx, 'AABCP1234E');
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoices = app(TripInvoiceService::class);
    $invoice = $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, [
        'number_mode' => 'manual',
        'manual_number' => 'OF-INV-1001',
        'charges' => [[
            'charge_type' => 'detention',
            'description' => 'Waiting at gate',
            'amount' => '500',
        ]],
    ]);

    expect($invoice->status)->toBe(TripInvoiceStatus::Pending)
        ->and($invoice->invoice_number)->toBe('OF-INV-1001')
        ->and($invoice->billing_customer_party_id)->toBe($customer->id)
        ->and($invoice->invoice_tds_rate_percent)->toBe('2.00')
        ->and($invoice->gst_rate_snapshot)->not->toBeNull()
        ->and($invoice->base_freight_total)->toBe((string) $lr->fresh()->freight_total)
        ->and($invoice->additional_charges_total)->toBe('500.00')
        ->and(bccomp((string) $invoice->gst_amount, '0', 2))->toBe(1);
});

test('consolidated invoice requires same billing customer', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $invoices = app(TripInvoiceService::class);

    $trips = [];
    foreach (range(1, 2) as $i) {
        $trip = tripCreatePendingTrip($ctx);
        $lr = $trip->lorryReceipt()->firstOrFail();
        tripAssignBillingCustomer($lr, $customer, $ctx);
        tripCompleteTrip($ctx, $trip->fresh());
        $trips[] = $lr->fresh();
    }

    $invoice = $invoices->createConsolidated($ctx['company'], $ctx['branch'], $ctx['member'], [
        $trips[0]->id,
        $trips[1]->id,
    ], []);

    expect($invoice->lines)->toHaveCount(2);
});

test('active invoice blocks duplicate lr invoicing', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoices = app(TripInvoiceService::class);
    $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, []);

    expect(fn () => $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, []))
        ->toThrow(TripInvoiceConflictException::class);
});

test('unpaid invoice can be cancelled and lr re-invoiced', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoices = app(TripInvoiceService::class);
    $first = $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, [
        'number_mode' => 'manual',
        'manual_number' => 'OF-INV-CANCEL',
    ]);

    $invoices->cancel($first, $ctx['member'], 'Customer dispute');

    $second = $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, [
        'number_mode' => 'manual',
        'manual_number' => 'OF-INV-CANCEL-2',
    ]);

    expect($first->fresh()->status)->toBe(TripInvoiceStatus::Cancelled)
        ->and($second->invoice_number)->toBe('OF-INV-CANCEL-2');
});

test('manual invoice number is unique per company among active records', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $invoices = app(TripInvoiceService::class);

    foreach (range(1, 2) as $i) {
        $trip = tripCreatePendingTrip($ctx);
        $lr = $trip->lorryReceipt()->firstOrFail();
        tripAssignBillingCustomer($lr, $customer, $ctx);
        tripCompleteTrip($ctx, $trip->fresh());
        $ctx['lr'.$i] = $lr->fresh();
    }

    $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $ctx['lr1']->id, [
        'number_mode' => 'manual',
        'manual_number' => 'DUP-INV',
    ]);

    expect(fn () => $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $ctx['lr2']->id, [
        'number_mode' => 'manual',
        'manual_number' => 'DUP-INV',
    ]))->toThrow(TripInvoiceConflictException::class);
});

test('latest unpaid invoice without payments can be soft deleted', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoices = app(TripInvoiceService::class);
    $invoice = $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, [
        'number_mode' => 'manual',
        'manual_number' => 'OF-INV-DELETE',
    ]);

    $invoices->softDelete($invoice, $ctx['member']);

    expect($invoice->fresh()->trashed())->toBeTrue();
});

test('billing customer must hold active customer role', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);

    $party = Party::factory()->create([
        'company_id' => $ctx['company']->id,
        'name' => 'Non-customer party',
    ]);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $party, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    $invoices = app(TripInvoiceService::class);

    expect(fn () => $invoices->createIndividual($ctx['company'], $ctx['branch'], $ctx['member'], $lr->fresh()->id, []))
        ->toThrow(ValidationException::class);
});
