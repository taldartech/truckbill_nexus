<?php

use App\Domains\Broker\Enums\BookBalanceEntryType;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Broker\Voucher\VoucherPaymentService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Models\BankCashAccount;
use App\Models\LorryHire;
use App\Models\TransporterBookBalanceEntry;
use App\Models\TransporterVoucher;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    seedTransporterBookBalance($ctx, $hire, '1000.00');

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

    expect(fn () => app(VoucherPaymentService::class)->record(
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
    [$hireA, $hireB] = sameTruckHires($ctx);

    $created = app(VoucherPaymentService::class)->record(
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

test('adjust in advance within or equal to the book balance is accepted', function (string $amount, string $remaining) {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    seedTransporterBookBalance($ctx, $hire, '1000.00');

    adjustInAdvance($ctx, $hire, $amount);

    expect(app(TransporterBookBalanceService::class)->availableBalance(
        $ctx['company'],
        $ctx['branch'],
        (string) $hire->transporter_party_id,
    ))->toBe($remaining);
})->with([
    'below balance' => ['400.00', '600.00'],
    'equal to balance' => ['1000.00', '0.00'],
]);

test('adjust in advance above the book balance is rejected with the available amount', function (?string $balance, string $amount, string $message) {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    if ($balance !== null) {
        seedTransporterBookBalance($ctx, $hire, $balance);
    }

    try {
        adjustInAdvance($ctx, $hire, $amount);
        $this->fail('Adjustment above the Book Balance was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors()['amount'][0])->toBe($message);
    }

    expect(TransporterVoucher::query()->where('lorry_hire_id', $hire->id)->count())->toBe(0);
})->with([
    'above balance' => ['1000.00', '1000.01', 'Adjustment amount cannot exceed the available Book Balance of ₹1,000.'],
    'zero balance' => [null, '1.00', 'Adjustment amount cannot exceed the available Book Balance of ₹0.'],
]);

test('adjust in advance cannot exceed the remaining hire payable even with a larger book balance', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $remaining = app(TransporterVoucherService::class)->allocatableAmount($hire->fresh());
    seedTransporterBookBalance($ctx, $hire, bcadd($remaining, '5000.00', 2));

    expect(fn () => adjustInAdvance($ctx, $hire, bcadd($remaining, '0.01', 2)))
        ->toThrow(BrokerConflictException::class);
});

test('bulk adjust in advance validates the combined amount and creates nothing when it exceeds the balance', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    [$hireA, $hireB] = sameTruckHires($ctx);
    seedTransporterBookBalance($ctx, $hireA, '300.00');

    try {
        app(VoucherPaymentService::class)->record($ctx['company'], $ctx['branch'], $ctx['member'], [
            ['lorry_hire_id' => $hireA->id, 'amount' => '200.00'],
            ['lorry_hire_id' => $hireB->id, 'amount' => '200.00'],
        ], [
            'payment_type' => VoucherPaymentType::Advance->value,
            'payment_mode' => VoucherPaymentMode::AdjustInAdvance->value,
        ]);
        $this->fail('Combined adjustment above the Book Balance was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors()['lines'][0])
            ->toBe('Total adjustment of ₹400 cannot exceed the available Book Balance of ₹300.');
    }

    expect(TransporterVoucher::query()->whereIn('lorry_hire_id', [$hireA->id, $hireB->id])->count())->toBe(0);
});

test('book balance consumption is serialized with a transporter row lock', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    [$hireA, $hireB] = sameTruckHires($ctx);
    seedTransporterBookBalance($ctx, $hireA, '1000.00');

    $lockedParties = 0;
    DB::listen(function ($query) use (&$lockedParties): void {
        if (str_contains($query->sql, 'parties') && str_contains(strtolower($query->sql), 'for update')) {
            $lockedParties++;
        }
    });

    adjustInAdvance($ctx, $hireA, '800.00');

    expect($lockedParties)->toBeGreaterThan(0);

    expect(fn () => adjustInAdvance($ctx, $hireB, '300.00'))
        ->toThrow(ValidationException::class, 'Adjustment amount cannot exceed the available Book Balance of ₹200.');
});

test('adjust in advance reduces hire outstanding once, not twice', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $outstandingBefore = (string) $hire->fresh()->outstanding_amount;
    seedTransporterBookBalance($ctx, $hire, '1000.00');

    $voucher = adjustInAdvance($ctx, $hire, '400.00');
    app(TransporterVoucherService::class)->transitionStatus($voucher, $ctx['member'], VoucherStatus::Paid);

    expect((string) $hire->fresh()->outstanding_amount)->toBe(bcsub($outstandingBefore, '400.00', 2));
});

test('same day duplicate voucher requires explicit confirmation', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    $service = app(TransporterVoucherService::class);
    $payload = [
        'amount' => '500.00',
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ];

    $service->createForLorryHire($hire, $ctx['member'], $payload);

    expect(fn () => $service->createForLorryHire($hire, $ctx['member'], $payload))
        ->toThrow(ValidationException::class, 'Confirm to record it again.');

    $service->createForLorryHire($hire, $ctx['member'], [...$payload, 'confirm_duplicate' => true]);

    expect(TransporterVoucher::query()->where('lorry_hire_id', $hire->id)->count())->toBe(2);
});

/**
 * @param  array<string, mixed>  $ctx
 */
function seedTransporterBookBalance(array $ctx, LorryHire $hire, string $amount): void
{
    app(TransporterBookBalanceService::class)->createSignedEntry($ctx['company'], $ctx['branch'], $ctx['member'], [
        'transporter_party_id' => $hire->transporter_party_id,
        'amount' => $amount,
        'entry_type' => BookBalanceEntryType::AdvanceAdjustment->value,
        'source_type' => 'test_seed',
        'source_id' => (string) Str::uuid(),
    ]);
}

/**
 * @param  array<string, mixed>  $ctx
 */
function adjustInAdvance(array $ctx, LorryHire $hire, string $amount): TransporterVoucher
{
    return app(TransporterVoucherService::class)->createForLorryHire($hire->fresh(), $ctx['member'], [
        'amount' => $amount,
        'payment_type' => VoucherPaymentType::Advance->value,
        'payment_mode' => VoucherPaymentMode::AdjustInAdvance->value,
    ]);
}

/**
 * Two Lorry Hires for the same transporter and truck (bulk voucher eligible).
 *
 * @param  array<string, mixed>  $ctx
 * @return array{0: LorryHire, 1: LorryHire}
 */
function sameTruckHires(array $ctx): array
{
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

    return [$hireA, $hireB];
}
