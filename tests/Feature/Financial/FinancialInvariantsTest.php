<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Master\Catalogue\Enums\OwnerCatalogueType;
use App\Domains\OwnFleet\Invoice\TripInvoicePaymentService;
use App\Domains\OwnFleet\Trip\TripExpenseService;
use App\Models\BrokerInvoice;
use App\Models\OwnerCatalogueItem;
use App\Models\TransporterBookBalanceEntry;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('own fleet trip profit formula matches domain profit summary', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);
    $expenses = app(TripExpenseService::class);
    $summary = $expenses->profitSummary($trip->fresh(['lorryReceipt']));
    $expectedProfit = bcsub(bcsub($summary['agreed_freight'], $summary['diesel_cost'], 2), $summary['other_expenses'], 2);
    assertBcEqual($expectedProfit, $summary['trip_profit'], 'trip profit formula');
});

test('own fleet trip profit follows agreed freight minus recorded diesel and toll', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $trip = tripCreatePendingTrip($ctx);

    $expenses = app(TripExpenseService::class);
    $fuel = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Fuel',
        'code' => 'FUEL2',
        'is_active' => true,
    ]);
    $expenses->add($trip, $ctx['member'], [
        'expense_category_id' => $fuel->id,
        'expense_date' => now()->toDateString(),
        'is_diesel' => true,
        'diesel_litres' => '10',
        'diesel_rate_per_litre' => '100',
        'diesel_odometer' => '1000',
    ]);
    $toll = OwnerCatalogueItem::factory()->create([
        'owner_user_id' => $ctx['owner']->id,
        'catalogue_type' => OwnerCatalogueType::ExpenseCategory->value,
        'name' => 'Toll',
        'code' => 'TOLL2',
        'is_active' => true,
    ]);
    $expenses->add($trip, $ctx['member'], [
        'expense_category_id' => $toll->id,
        'expense_date' => now()->toDateString(),
        'amount' => '200',
    ]);

    $summary = $expenses->profitSummary($trip->fresh(['lorryReceipt']));
    // LR freight 12000 − diesel 1000 − other 200
    $expectedProfit = bcsub(bcsub($summary['agreed_freight'], $summary['diesel_cost'], 2), $summary['other_expenses'], 2);
    assertBcEqual($expectedProfit, $summary['trip_profit'], 'trip profit formula');
});

test('own fleet invoice total equals settlement plus balance after payments', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $invoice = financialOwnFleetInvoiceReady($ctx);
    assertOwnFleetInvoiceSettlementInvariant($invoice);

    $payments = app(TripInvoicePaymentService::class);
    $payments->record($invoice, $ctx['member'], [
        'amount_received' => '500',
        'tds_amount' => '100',
    ]);
    assertOwnFleetInvoiceSettlementInvariant($invoice->fresh());

    $payments->record($invoice->fresh(), $ctx['member'], [
        'fill_net' => true,
        'tds_amount' => '0',
        'shortage_damage' => '0',
        'discount' => '0',
    ]);
    assertOwnFleetInvoiceSettlementInvariant($invoice->fresh());
});

test('broker lorry hire net payable reflects freight tds adjustments and vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    assertLorryHirePayableInvariant($hire);

    $calc = app(LorryHireCalculationService::class);
    $before = $calc->summarize($hire);

    app(BrokerAdjustmentService::class)->createDetention($hire, $ctx['member'], ['amount' => '300.00']);
    $hire->refresh();
    assertLorryHirePayableInvariant($hire);
    expect(bccomp($calc->summarize($hire)['net_payable'], $before['net_payable'], 2))->toBe(1);

    app(TransporterVoucherService::class)->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => '400.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]);
    assertLorryHirePayableInvariant($hire->fresh());
});

test('broker customer invoice total equals recorded settlements plus balance', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    tripEnsureCompanyGstSnapshot($ctx['company']);

    $hire = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    brokerDeliverLr($hire->lorryReceipt, $ctx);

    $service = app(BrokerInvoiceService::class);
    /** @var BrokerInvoice $invoice */
    $invoice = $service->createInvoice($ctx['company'], $ctx['branch'], $ctx['member'], [
        'billing_customer_party_id' => $customer->id,
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
    ]);

    assertBrokerInvoiceSettlementInvariant($invoice);

    $service->recordPayment($invoice, $ctx['member'], [
        'amount_received' => '200.00',
        'tds_amount' => '50.00',
        'deduction_amount' => '25.00',
    ]);
    assertBrokerInvoiceSettlementInvariant($invoice->fresh());

    $remaining = (string) $invoice->fresh()->balance_due;
    $service->recordPayment($invoice->fresh(), $ctx['member'], [
        'amount_received' => $remaining,
    ]);
    assertBrokerInvoiceSettlementInvariant($invoice->fresh());
});

test('adjust in advance voucher book entry reconciles with hire outstanding', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    app(TransporterBookBalanceService::class)->createSignedEntry($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $hire->transporter_party_id,
        'amount' => '500.00',
        'entry_type' => BookBalanceEntryType::AdvanceAdjustment->value,
        'source_type' => 'test_seed',
        'source_id' => (string) Str::uuid(),
    ]);
    $netPayable = app(LorryHireCalculationService::class)->summarize($hire->fresh())['net_payable'];

    $voucher = app(TransporterVoucherService::class)->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => '150.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::AdjustInAdvance->value,
    ]);

    $entry = TransporterBookBalanceEntry::query()
        ->where('transporter_voucher_id', $voucher->id)
        ->firstOrFail();

    expect((string) $entry->amount)->toBe('-150.00');
    assertLorryHirePayableInvariant($hire->fresh());

    app(TransporterVoucherService::class)->transitionStatus($voucher, $ctx['member'], VoucherStatus::Paid);

    assertLorryHirePayableInvariant($hire->fresh());
    expect((string) $hire->fresh()->outstanding_amount)->toBe(bcsub($netPayable, '150.00', 2));
});
