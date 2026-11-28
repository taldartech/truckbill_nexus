<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Feature/Freight/FreightBookingTest.php
 *
 * Financial assertions follow the legacy document formula. In-progress vouchers reserve
 * payable (approved Nexus reservation) so remaining payable matches legacy balance.
 */

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireRevisionService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\PartyRole;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
    brokerGrantLorryHirePermissions();
    brokerGrantDeliveryAdjustmentPermissions();
    brokerGrantVoucherPermissions();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    TenantContextHolder::clear();
});

/**
 * @param  array<string, mixed>  $ctx
 */
function freightParityLr(array $ctx): LorryReceipt
{
    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Cement',
        'package_count' => 1,
        'actual_weight' => 10000,
        'charged_weight' => 10000,
        'freight_rate_type' => FreightRateType::PerChargedWeight->value,
        'freight_rate' => 1000,
    ]]);

    PartyRole::query()
        ->where('party_id', $lr->intended_payee_party_id)
        ->where('role', PartyRoleType::Transporter->value)
        ->firstOrFail()
        ->forceFill(['role_attributes' => [
            'pan' => 'BRKTR5678B',
            'pan_status' => 'active',
            'declaration' => false,
        ]])->save();

    return $lr->fresh();
}

/**
 * @param  array<string, mixed>  $ctx
 */
function freightParityHire(array $ctx, ?LorryReceipt $lr = null): LorryHire
{
    $lr ??= freightParityLr($ctx);

    return app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'hire_date' => now()->toDateString(),
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows()->firstOrFail()->id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1000',
        ]],
        'tds_amount' => '999',
        'advance_amount' => '2500',
        'tr_amount' => '1',
    ]);
}

/**
 * @return array<string, mixed>
 */
function freightParityRevisePayload(LorryHire $hire, array $extra = []): array
{
    $hire->loadMissing(['goodsLines', 'customAmounts']);
    $line = $hire->goodsLines->firstOrFail();

    return array_merge([
        'hire_date' => $hire->hire_date?->toDateString(),
        'edit_remark' => 'Legacy freight booking update',
        'goods_lines' => [[
            'lr_goods_row_id' => $line->lr_goods_row_id,
            'rate_type' => $line->rate_type->value,
            'rate' => $line->rate,
        ]],
        'custom_amounts' => $hire->customAmounts->map(fn ($row): array => [
            'id' => $row->id,
            'description' => $row->description,
            'amount' => number_format((float) $row->amount, 2, '.', ''),
        ])->all(),
    ], $extra);
}

test('LEG-BRO-00137 LEG-BRO-00138 store creates hire from bilty and ignores client financial totals', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = freightParityLr($ctx);
    $hire = freightParityHire($ctx, $lr);

    expect($hire->lorry_receipt_id)->toBe($lr->id)
        ->and($hire->freight_total)->toBe('10000.00')
        ->and($hire->tds_amount)->toBe('200.00')
        ->and($hire->paid_amount)->toBe('0.00')
        ->and($hire->outstanding_amount)->toBe('9800.00');
});

test('LEG-BRO-00139 store prevents a second active hire for the same bilty', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = freightParityHire($ctx);

    expect(fn () => freightParityHire($ctx, $hire->lorryReceipt))
        ->toThrow(BrokerConflictException::class, 'already has an active Lorry Hire');
});

test('LEG-BRO-00140 member with create permission can create hire and viewer cannot', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = freightParityLr($ctx);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows()->firstOrFail()->id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1000',
        ]],
    ]);

    expect($hire->company_id)->toBe($ctx['company']->id);

    $other = freightParityLr($ctx);
    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($other, $ctx['viewer'], [
        'goods_lines' => [[
            'lr_goods_row_id' => $other->goodsRows()->firstOrFail()->id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1000',
        ]],
    ]))->toThrow(BrokerAuthorizationException::class);
});

test('LEG-BRO-00143 update does not change tds when submission date has passed', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-02-05 12:00:00', 'Asia/Kolkata'));
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = freightParityHire($ctx);
    $hire->lorryReceipt?->forceFill(['lr_date' => '2025-01-15'])->save();

    $preview = app(LorryHireRevisionService::class)->preview(
        $hire->fresh(['lorryReceipt']),
        $ctx['member'],
        freightParityRevisePayload($hire, [
            'goods_lines' => [[
                'lr_goods_row_id' => $hire->goodsLines()->firstOrFail()->lr_goods_row_id,
                'rate_type' => FreightRateType::PerChargedWeight->value,
                'rate' => '900',
            ]],
        ]),
    );

    expect($preview['tds_locked_reason'])->toContain('TDS submission date')
        ->and($preview['after']['tds_amount'])->toBe('200.00')
        ->and($preview['after']['freight_total'])->toBe('9000.00');
});

test('LEG-BRO-00148 document formula recomputes freight tds and remaining after unloading adjustments and reserved vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = freightParityHire($ctx);
    $hire->forceFill(['tds_percent' => '20.000', 'tds_amount' => '2000.00', 'outstanding_amount' => '8000.00'])->save();
    brokerDeliverLr($hire->lorryReceipt, $ctx, 'MEMO-DOC-1');

    $adjustments = app(BrokerAdjustmentService::class);
    $adjustments->createUnloadingWeight($hire->fresh(), $ctx['member'], ['new_weight' => '8000']);
    $hire = $hire->fresh();
    app(LorryHireRevisionService::class)->revise($hire, $ctx['member'], freightParityRevisePayload($hire, [
        'goods_lines' => [[
            'lr_goods_row_id' => $hire->goodsLines()->firstOrFail()->lr_goods_row_id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1100',
        ]],
    ]));

    $hire = $hire->fresh();
    $adjustments->createDetention($hire, $ctx['member'], ['amount' => '100']);
    $adjustments->createDeviation($hire->fresh(), $ctx['member'], ['amount' => '40']);
    $adjustments->createMiscExpense($hire->fresh(), $ctx['member'], ['amount' => '30']);
    $adjustments->createDamage($hire->fresh(), $ctx['member'], ['quantity' => '1', 'rate' => '20', 'amount' => '20']);
    $adjustments->createShortage($hire->fresh(), $ctx['member'], ['quantity' => '1', 'rate' => '50', 'amount' => '50']);

    $vouchers = app(TransporterVoucherService::class);
    foreach (['1000.00', '500.00'] as $amount) {
        $vouchers->createForLorryHire($hire->fresh(), $ctx['member'], [
            'amount' => $amount,
            'payment_type' => VoucherPaymentType::Partial->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ]);
    }

    $summary = app(LorryHireCalculationService::class)->summarize($hire->fresh(['adjustments', 'vouchers', 'customAmounts', 'bookBalanceEntries']));

    expect($hire->fresh()->freight_total)->toBe('8800.00')
        ->and($hire->fresh()->tds_amount)->toBe('2200.00')
        ->and($summary['reserved_voucher_amount'])->toBe('1500.00')
        ->and($summary['remaining_payable'])->toBe('5140.00');
});

test('LEG-BRO-00159 positive custom amount increases remaining payable', function () {
    $summary = freightParityCustomSummary([['description' => 'Loading charge', 'amount' => '500']]);

    expect($summary['remaining_payable'])->toBe('5600.00');
});

test('LEG-BRO-00160 negative custom amount decreases remaining payable', function () {
    $summary = freightParityCustomSummary([['description' => 'Rate deduction', 'amount' => '-200']]);

    expect($summary['remaining_payable'])->toBe('4900.00');
});

test('LEG-BRO-00161 multiple custom amounts sum into remaining payable', function () {
    $summary = freightParityCustomSummary([
        ['description' => 'Loading charge', 'amount' => '300'],
        ['description' => 'Unloading charge', 'amount' => '200'],
    ]);

    expect($summary['remaining_payable'])->toBe('5600.00');
});

test('LEG-BRO-00162 removed custom amount row no longer affects remaining payable', function () {
    $ctx = freightParityPreparedHire();
    $hire = $ctx['hire'];
    $service = app(LorryHireRevisionService::class);
    $service->revise($hire, $ctx['member'], freightParityRevisePayload($hire, [
        'custom_amounts' => [
            ['description' => 'Loading charge', 'amount' => '500'],
            ['description' => 'Unloading charge', 'amount' => '300'],
        ],
    ]));
    $hire = $hire->fresh(['customAmounts', 'goodsLines']);
    $keep = $hire->customAmounts->firstWhere('description', 'Loading charge');
    $service->revise($hire, $ctx['member'], freightParityRevisePayload($hire, [
        'custom_amounts' => [[
            'id' => $keep->id,
            'description' => 'Loading charge',
            'amount' => '500',
        ]],
    ]));

    $summary = app(LorryHireCalculationService::class)->summarize($hire->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']));

    expect($hire->fresh(['customAmounts'])->customAmounts)->toHaveCount(1)
        ->and($summary['remaining_payable'])->toBe('5600.00');
});

test('LEG-BRO-00163 custom amounts are additive with detention', function () {
    $ctx = freightParityPreparedHire();
    app(BrokerAdjustmentService::class)->createDetention($ctx['hire'], $ctx['member'], ['amount' => '100']);
    app(LorryHireRevisionService::class)->revise($ctx['hire']->fresh(), $ctx['member'], freightParityRevisePayload($ctx['hire'], [
        'custom_amounts' => [['description' => 'Loading charge', 'amount' => '500']],
    ]));

    $summary = app(LorryHireCalculationService::class)->summarize($ctx['hire']->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']));

    expect($summary['remaining_payable'])->toBe('5700.00');
});

test('LEG-BRO-00164 voucher reservation respects custom amounts', function () {
    $ctx = freightParityPreparedHire();
    app(LorryHireRevisionService::class)->revise($ctx['hire'], $ctx['member'], freightParityRevisePayload($ctx['hire'], [
        'custom_amounts' => [['description' => 'Loading charge', 'amount' => '1000']],
    ]));
    $hire = $ctx['hire']->fresh();
    $summary = app(LorryHireCalculationService::class)->summarize($hire->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']));

    app(TransporterVoucherService::class)->createForLorryHire($hire, $ctx['member'], [
        'amount' => $summary['remaining_payable'],
        'payment_type' => VoucherPaymentType::Final->value,
        'payment_mode' => VoucherPaymentMode::Cash->value,
    ]);

    $after = app(LorryHireCalculationService::class)->summarize($hire->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']));

    expect($after['remaining_payable'])->toBe('0.00')
        ->and($after['net_payable'])->toBe(bcadd('6600.00', '1000.00', 2));
});

test('LEG-BRO-00167 LEG-BRO-00168 LEG-BRO-00169 LEG-BRO-00170 memo date is stored, defaults to today, and rejects the future', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 13:55:00', 'Asia/Kolkata'));
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $delivery = app(BrokerDeliveryService::class);
    $futureHire = freightParityHire($ctx);

    expect(fn () => $delivery->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$futureHire->lorry_receipt_id],
        'memo_number' => 'MEMO-FUTURE',
        'memo_date' => '2026-10-08',
    ]))->toThrow(ValidationException::class, 'cannot be in the future');
    expect($futureHire->lorryReceipt->fresh()->delivery)->toBeNull();

    $dated = $delivery->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$futureHire->lorry_receipt_id],
        'memo_number' => 'MEMO-101',
        'memo_date' => '2026-10-01',
    ])->first();
    expect($dated?->memo_number)->toBe('MEMO-101')
        ->and($dated?->delivery_date?->toDateString())->toBe('2026-10-01');

    $defaultHire = freightParityHire($ctx);
    $defaults = $delivery->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$defaultHire->lorry_receipt_id],
        'memo_number' => 'MEMO-102',
    ])->first();
    expect($defaults?->delivery_date?->toDateString())->toBe('2026-10-07');

    $bulkHire = freightParityHire($ctx);
    $bulk = $delivery->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$bulkHire->lorry_receipt_id],
        'memo_number' => 'MEMO-201',
        'memo_date' => '2026-10-02',
    ])->first();
    expect($bulk?->delivery_date?->toDateString())->toBe('2026-10-02');
});

/**
 * Hire at legacy custom-amount baseline: 8 MT × 1100, 20% TDS, ₹1,500 reserved.
 *
 * @return array{hire: LorryHire, member: mixed}
 */
function freightParityPreparedHire(): array
{
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = freightParityHire($ctx);
    $hire->forceFill(['tds_percent' => '20.000', 'tds_amount' => '2000.00', 'outstanding_amount' => '8000.00'])->save();
    brokerDeliverLr($hire->lorryReceipt, $ctx, 'MEMO-CA-'.substr((string) $hire->id, 0, 8));
    app(BrokerAdjustmentService::class)->createUnloadingWeight($hire->fresh(), $ctx['member'], ['new_weight' => '8000']);
    $hire = $hire->fresh();
    app(LorryHireRevisionService::class)->revise($hire, $ctx['member'], freightParityRevisePayload($hire, [
        'goods_lines' => [[
            'lr_goods_row_id' => $hire->goodsLines()->firstOrFail()->lr_goods_row_id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => '1100',
        ]],
    ]));
    $hire = $hire->fresh();
    $vouchers = app(TransporterVoucherService::class);
    foreach (['1000.00', '500.00'] as $amount) {
        $vouchers->createForLorryHire($hire, $ctx['member'], [
            'amount' => $amount,
            'payment_type' => VoucherPaymentType::Partial->value,
            'payment_mode' => VoucherPaymentMode::Cash->value,
        ]);
    }

    return ['hire' => $hire->fresh(), 'member' => $ctx['member']];
}

/**
 * @param  list<array{description: string, amount: string}>  $rows
 * @return array<string, string>
 */
function freightParityCustomSummary(array $rows): array
{
    $ctx = freightParityPreparedHire();
    app(LorryHireRevisionService::class)->revise($ctx['hire'], $ctx['member'], freightParityRevisePayload($ctx['hire'], [
        'custom_amounts' => $rows,
    ]));

    return app(LorryHireCalculationService::class)->summarize(
        $ctx['hire']->fresh(['customAmounts', 'adjustments', 'vouchers', 'bookBalanceEntries']),
    );
}
