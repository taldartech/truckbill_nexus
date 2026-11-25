<?php

use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\BulkVoucherService;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Models\BankCashAccount;
use App\Models\TransporterBookBalanceEntry;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantVoucherPermissions();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('voucher default status follows payment mode', function () {
    $service = app(TransporterVoucherService::class);

    expect($service->defaultStatusForMode(VoucherPaymentMode::Bank))->toBe(VoucherStatus::Pending)
        ->and($service->defaultStatusForMode(VoucherPaymentMode::PaidToPump))->toBe(VoucherStatus::Pending)
        ->and($service->defaultStatusForMode(VoucherPaymentMode::Cash))->toBe(VoucherStatus::InProgress)
        ->and($service->defaultStatusForMode(VoucherPaymentMode::AdjustInAdvance))->toBe(VoucherStatus::InProgress);
});

test('cash voucher creates in progress and transitions to paid', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $voucher = app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => '1000.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]);

    expect($voucher->status)->toBe(VoucherStatus::InProgress);

    $paid = app(TransporterVoucherService::class)->transitionStatus(
        $voucher,
        $ctx['member'],
        VoucherStatus::Paid,
    );

    expect($paid->status)->toBe(VoucherStatus::Paid);

    $hire->refresh();
    expect($hire->paid_amount)->toBe('1000.00');
});

test('bank voucher starts pending and follows pending to in progress to paid', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $account = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'name' => 'Broker Test Bank',
        'account_kind' => 'bank',
        'is_active' => true,
    ]);

    $service = app(TransporterVoucherService::class);
    $voucher = $service->createForLorryHire($hire, $ctx['member'], [
        'amount' => '500.00',
        'payment_type' => VoucherPaymentType::Partial->value,
        'payment_mode' => VoucherPaymentMode::Bank->value,
        'bank_cash_account_id' => $account->id,
    ]);

    expect($voucher->status)->toBe(VoucherStatus::Pending);

    $voucher = $service->transitionStatus($voucher, $ctx['member'], VoucherStatus::InProgress);
    expect($voucher->status)->toBe(VoucherStatus::InProgress);

    $voucher = $service->transitionStatus($voucher, $ctx['member'], VoucherStatus::Paid);
    expect($voucher->status)->toBe(VoucherStatus::Paid);
});

test('overpayment against lorry hire is rejected', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(TransporterVoucherService::class);

    $outstanding = (string) $hire->fresh()->outstanding_amount;

    expect(fn () => $service->createForLorryHire($hire, $ctx['member'], [
        'amount' => bcadd($outstanding, '1.00', 2),
        'payment_type' => VoucherPaymentType::Final->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]))->toThrow(BrokerConflictException::class);
});

test('adjust in advance creates debit book balance entry idempotently', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $voucher = app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => '250.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::AdjustInAdvance->value,
    ]);

    $entry = TransporterBookBalanceEntry::query()
        ->where('transporter_voucher_id', $voucher->id)
        ->first();

    expect($entry)->not->toBeNull()
        ->and((string) $entry->amount)->toBe('-250.00');

    $duplicate = app(TransporterBookBalanceService::class)->createSignedEntry(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        [
            'transporter_party_id' => $hire->transporter_party_id,
            'lorry_hire_id' => $hire->id,
            'transporter_voucher_id' => $voucher->id,
            'amount' => '-250.00',
            'entry_type' => BookBalanceEntryType::AdjustInAdvanceVoucher->value,
            'source_type' => TransporterBookBalanceService::SOURCE_VOUCHER_ADJUST,
            'source_id' => $voucher->id,
        ],
    );

    expect($duplicate->id)->toBe($entry->id);
});

test('bulk vouchers reject mixed transporter and vehicle', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hireA = brokerCreateLorryHire($ctx);
    $otherMarket = brokerCreateTransporterAndMarketVehicle($ctx);

    $lrB = app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::Broker->value,
        'vehicle_id' => $otherMarket['vehicle']->id,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_text' => 'Mumbai',
        'to_text' => 'Pune',
        'consignor_party_id' => $hireA->lorryReceipt->consignor_party_id,
        'consignee_party_id' => $hireA->lorryReceipt->consignee_party_id,
        'billing_customer_party_id' => $hireA->lorryReceipt->billing_customer_party_id,
        'intended_payee_type' => 'owner',
        'intended_payee_party_id' => $otherMarket['transporter']->id,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Other transporter',
            'package_count' => 4,
            'actual_weight' => 50,
            'charged_weight' => 50,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 4500,
        ]],
    ]);

    $hireB = app(LorryHireService::class)->createFromLorryReceipt($lrB, $ctx['member'], [
        'transporter_party_id' => $otherMarket['transporter']->id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrB),
    ]);

    $bulk = app(BulkVoucherService::class);

    expect(fn () => $bulk->createBulk(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        [
            ['lorry_hire_id' => $hireA->id, 'amount' => '100.00'],
            ['lorry_hire_id' => $hireB->id, 'amount' => '100.00'],
        ],
        [
            'payment_type' => VoucherPaymentType::Advance->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ],
    ))->toThrow(BrokerConflictException::class);
});

test('bulk vouchers create separate voucher per hire for same transporter and vehicle', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lrA = brokerCreateBrokerLr($ctx);
    $hireA = app(LorryHireService::class)->createFromLorryReceipt($lrA, $ctx['member'], [
        'transporter_party_id' => $lrA->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrA),
    ]);

    $lrB = app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::Broker->value,
        'vehicle_id' => $lrA->vehicle_id,
        'number_mode' => 'auto',
        'lr_date' => now()->toDateString(),
        'from_text' => 'Mumbai',
        'to_text' => 'Nashik',
        'consignor_party_id' => $lrA->consignor_party_id,
        'consignee_party_id' => $lrA->consignee_party_id,
        'billing_customer_party_id' => $lrA->billing_customer_party_id,
        'intended_payee_type' => 'owner',
        'intended_payee_party_id' => $lrA->intended_payee_party_id,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Bulk second LR',
            'package_count' => 5,
            'actual_weight' => 80,
            'charged_weight' => 80,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 6000,
        ]],
    ]);

    $hireB = app(LorryHireService::class)->createFromLorryReceipt($lrB, $ctx['member'], [
        'transporter_party_id' => $lrB->intended_payee_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrB),
    ]);

    expect($hireA->transporter_party_id)->toBe($hireB->transporter_party_id)
        ->and($hireA->vehicle_id)->toBe($hireB->vehicle_id);

    $bulk = app(BulkVoucherService::class);
    $preview = $bulk->preview(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        [
            ['lorry_hire_id' => $hireA->id, 'amount' => '100.00'],
            ['lorry_hire_id' => $hireB->id, 'amount' => '100.00'],
        ],
        [
            'payment_type' => VoucherPaymentType::Advance->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ],
    );

    expect($preview['lines'][0]['accepted'])->toBeTrue()
        ->and($preview['lines'][1]['accepted'])->toBeTrue();

    $created = $bulk->createBulk(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        [
            ['lorry_hire_id' => $hireA->id, 'amount' => '100.00'],
            ['lorry_hire_id' => $hireB->id, 'amount' => '150.00'],
        ],
        [
            'payment_type' => VoucherPaymentType::Advance->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ],
    );

    expect($created)->toHaveCount(2);
});

test('reserved pending vouchers block over-allocation on second create', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(TransporterVoucherService::class);
    $outstanding = (string) $hire->fresh()->outstanding_amount;
    $half = bcdiv($outstanding, '2', 2);

    $account = BankCashAccount::query()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'name' => 'Lock Test Bank',
        'account_kind' => 'bank',
        'is_active' => true,
    ]);

    $service->createForLorryHire($hire, $ctx['member'], [
        'amount' => $half,
        'payment_type' => VoucherPaymentType::Partial->value,
        'payment_mode' => VoucherPaymentMode::Bank->value,
        'bank_cash_account_id' => $account->id,
    ]);

    expect(fn () => $service->createForLorryHire($hire, $ctx['member'], [
        'amount' => bcadd($half, '0.01', 2),
        'payment_type' => VoucherPaymentType::Partial->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]))->toThrow(BrokerConflictException::class);
});
