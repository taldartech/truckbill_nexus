<?php

use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Models\TripInvoice;
use App\Support\Audit\AuditEntry;
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

function tripInvoiceForChargesEdit(array $ctx): TripInvoice
{
    tripEnsureCompanyGstSnapshot($ctx['company']);
    $customer = tripCreateBillingCustomer($ctx);
    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt()->firstOrFail();
    tripAssignBillingCustomer($lr, $customer, $ctx);
    tripCompleteTrip($ctx, $trip->fresh());

    return app(TripInvoiceService::class)->createIndividual(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $lr->fresh()->id,
        [
            'number_mode' => 'manual',
            'manual_number' => 'CHG-'.uniqid(),
            'charges' => [[
                'charge_type' => 'detention',
                'description' => 'Initial detention',
                'amount' => '200',
            ]],
        ],
    );
}

test('pending invoice without payments allows charge edit with remark and audit', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = tripInvoiceForChargesEdit($ctx);
    $beforeAudit = AuditEntry::query()->count();

    $updated = app(TripInvoiceService::class)->updateCharges($invoice, $ctx['member'], [
        'edit_remark' => 'Customer agreed revised detention',
        'charges' => [[
            'charge_type' => 'demurrage',
            'description' => 'Revised demurrage',
            'amount' => '750',
        ]],
    ]);

    expect($updated->additional_charges_total)->toBe('750.00')
        ->and($updated->charges)->toHaveCount(1)
        ->and($updated->charges->first()->description)->toBe('Revised demurrage')
        ->and(AuditEntry::query()->count())->toBe($beforeAudit + 1);
});

test('charge edit requires remark', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = tripInvoiceForChargesEdit($ctx);

    expect(fn () => app(TripInvoiceService::class)->updateCharges($invoice, $ctx['member'], [
        'edit_remark' => '   ',
        'charges' => [],
    ]))->toThrow(ValidationException::class);
});

test('first payment locks additional charge edits', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = tripInvoiceForChargesEdit($ctx);

    app(TripInvoicePaymentService::class)->record($invoice, $ctx['member'], [
        'amount_received' => '100',
    ]);

    $locked = $invoice->fresh();

    expect($locked->charges_locked_at)->not->toBeNull();

    expect(fn () => app(TripInvoiceService::class)->updateCharges($locked, $ctx['member'], [
        'edit_remark' => 'Too late',
        'charges' => [],
    ]))->toThrow(TripInvoiceConflictException::class);
});
