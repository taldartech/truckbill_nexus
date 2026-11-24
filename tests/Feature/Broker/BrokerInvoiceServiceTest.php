<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\BrokerInvoiceStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\Invoice\Enums\BrokerInvoicePaymentStatus;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantInvoicePermissions();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('eligible list includes only delivered broker lrs', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $deliveredHire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($deliveredHire->lorryReceipt, $ctx);
    brokerDeliverLr($deliveredHire->lorryReceipt, $ctx);

    $pendingHire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($pendingHire->lorryReceipt, $ctx, $customer);

    $service = app(BrokerInvoiceService::class);
    $eligible = $service->listEligibleLorryReceipts($ctx['company'], $ctx['branch'], $customer->id);
    $ids = collect($eligible['with_memo'])->merge($eligible['without_memo'])->pluck('id');

    expect($ids)->toContain($deliveredHire->lorry_receipt_id)
        ->and($ids)->not->toContain($pendingHire->lorry_receipt_id);
});

test('multi-lr invoice combines memo and non-memo sections for same customer', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);

    $customer = null;
    $lrIds = [];

    $withMemo = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($withMemo->lorryReceipt, $ctx);
    $lrIds[] = brokerDeliverLr($withMemo->lorryReceipt, $ctx, 'MEMO-INV-1')->id;

    $lrB = brokerCreateBrokerLr($ctx);
    brokerSetBillingCustomer($lrB, $ctx, $customer);
    $hireB = app(LorryHireService::class)->createFromLorryReceipt($lrB, $ctx['member'], [
        'transporter_party_id' => $lrB->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrB),
    ]);
    $lrIds[] = brokerDeliverLr($hireB->lorryReceipt, $ctx)->id;

    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => $lrIds,
    ]);

    expect($invoice->status)->toBe(BrokerInvoiceStatus::Issued)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->billing_customer_party_id)->toBe($customer->id);
});

test('create invoice rejects mixed billing customers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hireA = brokerCreateLorryHire($ctx);
    $customerA = brokerSetBillingCustomer($hireA->lorryReceipt, $ctx);
    brokerDeliverLr($hireA->lorryReceipt, $ctx);

    $hireB = brokerCreateLorryHire($ctx);
    $customerB = brokerSetBillingCustomer($hireB->lorryReceipt, $ctx);
    brokerDeliverLr($hireB->lorryReceipt, $ctx);

    expect(fn () => app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customerA->id,
        'lorry_receipt_ids' => [$hireA->lorry_receipt_id, $hireB->lorry_receipt_id],
    ]))->toThrow(BrokerConflictException::class);

    expect($customerB->id)->not->toBe($customerA->id);
});

test('duplicate active invoice on same lr is prevented', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);

    $service = app(BrokerInvoiceService::class);
    $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]);

    expect(fn () => $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]))->toThrow(BrokerConflictException::class);
});

test('gst is computed on taxable customer freight', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);

    $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]);

    expect($invoice->gst_rate_snapshot)->not->toBeNull()
        ->and(bccomp((string) $invoice->gst_amount, '0', 2))->toBe(1)
        ->and(bccomp((string) $invoice->invoice_total, (string) $invoice->taxable_total, 2))->toBe(1);
});

test('customer invoice tds is separate from transporter lorry hire tds', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);
    $tdsBefore = $hire->fresh()->tds_amount;

    $service = app(BrokerInvoiceService::class);
    $invoice = $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]);

    $service->recordPayment($invoice, $ctx['member'], [
        'amount_received' => '1000',
        'tds_amount' => '150.00',
    ]);

    $invoice->refresh();
    $hire->refresh();

    expect((string) $invoice->customer_tds_amount)->toBe('150.00')
        ->and($hire->tds_amount)->toBe($tdsBefore);
});

test('invoice numbers are unique per branch allocation', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $numbers = [];
    foreach (range(1, 2) as $i) {
        $hire = brokerCreateLorryHire($ctx);
        $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
        brokerDeliverLr($hire->lorryReceipt, $ctx);

        $invoice = app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
            'billing_customer_party_id' => $customer->id,
            'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        ]);
        $numbers[] = $invoice->invoice_number;
    }

    expect($numbers[0])->not->toBe($numbers[1]);
});

test('cancel releases lrs for new invoice', function () {
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

    $service->cancel($invoice, $ctx['member'], 'Wrong customer totals');

    $invoice->refresh();
    expect($invoice->status)->toBe(BrokerInvoiceStatus::Cancelled);

    $replacement = $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]);

    expect($replacement->id)->not->toBe($invoice->id);
});

test('partial and full customer payments update status', function () {
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

    $total = (string) $invoice->invoice_total;
    $half = bcdiv($total, '2', 2);

    $service->recordPayment($invoice, $ctx['member'], [
        'amount_received' => $half,
    ]);

    $invoice->refresh();
    expect($invoice->payment_status)->toBe(BrokerInvoicePaymentStatus::Partial)
        ->and(bccomp((string) $invoice->balance_due, '0', 2))->toBe(1);

    $service->recordPayment($invoice, $ctx['member'], [
        'amount_received' => (string) $invoice->balance_due,
    ]);

    $invoice->refresh();
    expect($invoice->payment_status)->toBe(BrokerInvoicePaymentStatus::Paid)
        ->and($invoice->status)->toBe(BrokerInvoiceStatus::Paid)
        ->and(bccomp((string) $invoice->balance_due, '0', 2))->toBe(0);
});

test('shortage reduces customer invoice line total', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);

    app(BrokerAdjustmentService::class)->createShortage($hire, $ctx['member'], [
        'quantity' => '1',
        'rate' => '500',
    ]);

    $preview = app(BrokerInvoiceService::class)->previewAmounts($ctx['company'], $ctx['branch'], [$hire->lorry_receipt_id]);

    expect(bccomp($preview['shortage_damage_total'], '500.00', 2))->toBe(0);
});

test('unauthorized actor cannot create broker invoice', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);

    expect(fn () => app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['viewer'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]))->toThrow(BrokerAuthorizationException::class);
});

test('broker module entitlement is required', function () {
    $ctx = tripWorkspace([SubscriptionModule::OwnFleet->value]);
    tripActivate($ctx['company'], $ctx['branch']);
    brokerGrantInvoicePermissions();

    expect(fn () => app(BrokerInvoiceService::class)->createInvoice($ctx['company'], $ctx['branch'], $ctx['owner'], [
        'billing_customer_party_id' => (string) Str::uuid(),
        'lorry_receipt_ids' => [],
    ]))->toThrow(BrokerDomainException::class);
});
