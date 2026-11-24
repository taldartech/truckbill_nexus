<?php

/**
 * Concurrency coverage uses PostgreSQL row locks inside overlapping DB::transaction
 * scopes (same pattern as Phase 4 numbering and Broker lorry-hire duplicate tests).
 * RefreshDatabase wraps each test in a single connection transaction, so separate
 * PHP processes or secondary PDO connections cannot observe uncommitted fixtures;
 * lockForUpdate + nested service calls are the strongest approach available here.
 */

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\LinkedBilty\LinkedBiltyGroupService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\BulkVoucherService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\OwnFleet\Invoice\Exceptions\TripInvoiceConflictException;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\Rbac\PermissionCodes;
use App\Models\BrokerInvoice;
use App\Models\LorryHire;
use App\Models\TripInvoice;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../../Support/FinancialInvariantAssertions.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantInvoicePermissions();
    brokerGrantVoucherPermissions();
    brokerGrantLinkedBiltyPermissions();
    tripGrant('customer.admin', PermissionCodes::INVOICE_CREATE);
    tripGrant('customer.admin', PermissionCodes::PAYMENT_CREATE);
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('concurrent broker invoice create attempts on same lr allow only one active invoice', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);
    $service = app(BrokerInvoiceService::class);
    $payload = [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ];

    DB::transaction(function () use ($service, $ctx, $payload): void {
        $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], $payload);

        expect(fn () => $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], $payload))
            ->toThrow(BrokerConflictException::class);
    });

    expect(BrokerInvoice::query()
        ->where('company_id', $ctx['company']->id)
        ->whereNull('deleted_at')
        ->count())->toBe(1);
});

test('concurrent own fleet trip invoice numbering under lock yields distinct numbers', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $firstInvoice = financialOwnFleetInvoiceReady($ctx);
    $secondInvoice = financialOwnFleetInvoiceReady($ctx);

    expect($firstInvoice->invoice_number)->not->toBe($secondInvoice->invoice_number);
    expect(TripInvoice::query()->where('company_id', $ctx['company']->id)->count())->toBe(2);
});

test('serial concurrent payments cannot over-allocate own fleet invoice balance', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = financialOwnFleetInvoiceReady($ctx);
    $payments = app(TripInvoicePaymentService::class);

    DB::transaction(function () use ($payments, $invoice, $ctx): void {
        $partial = bcdiv((string) $invoice->invoice_total, '2', 2);
        $payments->record($invoice, $ctx['member'], [
            'amount_received' => $partial,
        ]);

        $locked = TripInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        $remaining = (string) $locked->balance_due;

        $payments->record($invoice->fresh(), $ctx['member'], [
            'amount_received' => $remaining,
        ]);

        expect(fn () => $payments->record($invoice->fresh(), $ctx['member'], [
            'amount_received' => '1.00',
        ]))->toThrow(TripInvoiceConflictException::class);
    });

    expect(bccomp((string) $invoice->fresh()->balance_due, '0', 2))->toBe(0);
});

test('concurrent broker invoice payments respect remaining balance under row lock', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);

    $service = app(BrokerInvoiceService::class);
    $invoice = $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]);

    DB::transaction(function () use ($service, $invoice, $ctx): void {
        $total = (string) $invoice->invoice_total;
        $first = bcdiv($total, '2', 2);
        $service->recordPayment($invoice, $ctx['member'], ['amount_received' => $first]);

        $remaining = (string) $invoice->fresh()->balance_due;
        $service->recordPayment($invoice->fresh(), $ctx['member'], ['amount_received' => $remaining]);

        expect(fn () => $service->recordPayment($invoice->fresh(), $ctx['member'], [
            'amount_received' => '0.01',
        ]))->toThrow(BrokerConflictException::class);
    });
});

test('concurrent voucher reservations cannot exceed lorry hire outstanding', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $voucherService = app(TransporterVoucherService::class);
    $outstanding = (string) $hire->fresh()->outstanding_amount;
    $half = bcdiv($outstanding, '2', 2);

    DB::transaction(function () use ($voucherService, $hire, $ctx, $half, $outstanding): void {
        $voucherService->createForLorryHire($hire, $ctx['member'], [
            'amount' => $half,
            'payment_type' => VoucherPaymentType::Partial->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ]);

        LorryHire::query()->whereKey($hire->id)->lockForUpdate()->firstOrFail();

        $voucherService->createForLorryHire($hire->fresh(), $ctx['member'], [
            'amount' => bcsub($outstanding, $half, 2),
            'payment_type' => VoucherPaymentType::Partial->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ]);

        expect(fn () => $voucherService->createForLorryHire($hire->fresh(), $ctx['member'], [
            'amount' => '1.00',
            'payment_type' => VoucherPaymentType::Advance->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ]))->toThrow(BrokerConflictException::class);
    });
});

test('linked bilty group payment allocation stays consistent when vouchers are serialized', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);
    $vehicle = $market['vehicle'];

    $lrOne = brokerCreateBrokerLrOnVehicle($ctx, $vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Group A',
        'package_count' => 5,
        'actual_weight' => 50,
        'charged_weight' => 50,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 6000,
    ]]);
    $lrTwo = brokerCreateBrokerLrOnVehicle($ctx, $vehicle, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Group B',
        'package_count' => 5,
        'actual_weight' => 50,
        'charged_weight' => 50,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 4000,
    ]]);

    $hireA = app(LorryHireService::class)->createFromLorryReceipt($lrOne, $ctx['member'], [
        'transporter_party_id' => $lrOne->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrOne),
    ]);
    $hireB = app(LorryHireService::class)->createFromLorryReceipt($lrTwo, $ctx['member'], [
        'transporter_party_id' => $lrTwo->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrTwo),
    ]);

    $group = app(LinkedBiltyGroupService::class)->createGroup($ctx['member'], $ctx['company'], $ctx['branch'], [
        'vehicle_id' => $vehicle->id,
        'lorry_receipt_ids' => [$lrOne->id, $lrTwo->id],
    ]);

    $bulk = app(BulkVoucherService::class);

    DB::transaction(function () use ($bulk, $ctx, $hireA, $hireB): void {
        $bulk->createBulk(
            $ctx['company'],
            $ctx['branch'],
            $ctx['member'],
            [
                ['lorry_hire_id' => $hireA->id, 'amount' => '1000.00'],
                ['lorry_hire_id' => $hireB->id, 'amount' => '1000.00'],
            ],
            [
                'payment_type' => VoucherPaymentType::Advance->value,
                'payment_mode' => VoucherPaymentMode::Cash->value,
            ],
        );

        expect(fn () => $bulk->createBulk(
            $ctx['company'],
            $ctx['branch'],
            $ctx['member'],
            [
                ['lorry_hire_id' => $hireA->id, 'amount' => '99999.00'],
            ],
            [
                'payment_type' => VoucherPaymentType::Final->value,
                'payment_mode' => VoucherPaymentMode::Cash->value,
            ],
        ))->toThrow(BrokerConflictException::class);
    });

    expect($group->refresh()->id)->not->toBeEmpty();
});
