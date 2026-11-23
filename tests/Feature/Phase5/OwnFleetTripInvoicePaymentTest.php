<?php

use App\Domains\OwnFleet\Invoice\Enums\TripInvoicePaymentStatus;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Models\TripInvoice;
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

function tripInvoiceReady(array $ctx): TripInvoice
{
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx, 'AAAHP1234A');
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    return app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lr->fresh()->id,
        ['number_mode' => 'manual', 'manual_number' => 'PAY-'.uniqid()],
    );
}

test('partial and full payments update settlement without overpayment', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = tripInvoiceReady($ctx);
    $payments = app(TripInvoicePaymentService::class);

    $payments->record($invoice, $ctx['member'], [
        'amount_received' => '1000',
        'tds_amount' => '50',
    ]);

    $invoice = $invoice->fresh();
    expect($invoice->payment_status)->toBe(TripInvoicePaymentStatus::Partial);

    $remaining = (string) $invoice->balance_due;
    $payments->record($invoice, $ctx['member'], [
        'fill_net' => true,
        'tds_amount' => '0',
        'shortage_damage' => '0',
        'discount' => '0',
    ]);

    expect($invoice->fresh()->payment_status)->toBe(TripInvoicePaymentStatus::Paid)
        ->and(bccomp((string) $invoice->fresh()->balance_due, '0', 2))->toBe(0);
});

test('overpayment and cumulative tds caps are enforced', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = tripInvoiceReady($ctx);
    $payments = app(TripInvoicePaymentService::class);

    expect(fn () => $payments->record($invoice, $ctx['member'], [
        'amount_received' => bcadd((string) $invoice->invoice_total, '1', 2),
    ]))->toThrow(TripInvoiceConflictException::class);

    $tdsCap = (string) $invoice->tds_basis_freight;

    expect(fn () => $payments->record($invoice, $ctx['member'], [
        'amount_received' => '0',
        'tds_amount' => bcadd($tdsCap, '1', 2),
    ]))->toThrow(TripInvoiceConflictException::class);
});

test('discount requires reason', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = tripInvoiceReady($ctx);
    $payments = app(TripInvoicePaymentService::class);

    expect(fn () => $payments->record($invoice, $ctx['member'], [
        'amount_received' => '100',
        'discount' => '50',
    ]))->toThrow(ValidationException::class);
});
