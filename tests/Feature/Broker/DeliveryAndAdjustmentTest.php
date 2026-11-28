<?php

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\BrokerDeliveryStatus;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Models\BrokerAdjustment;
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
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('memo bulk assign for same billing customer succeeds', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hireA = brokerCreateLorryHire($ctx);
    $customer = brokerSetBillingCustomer($hireA->lorryReceipt, $ctx);

    $lrB = brokerCreateBrokerLr($ctx);
    brokerSetBillingCustomer($lrB, $ctx, $customer);
    $hireB = app(LorryHireService::class)->createFromLorryReceipt($lrB, $ctx['member'], [
        'transporter_party_id' => $lrB->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lrB),
    ]);

    app(BrokerDeliveryService::class)->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hireA->lorry_receipt_id, $hireB->lorry_receipt_id],
        'memo_number' => 'MEMO-9001',
        'memo_date' => now()->subDay()->toDateString(),
    ]);

    foreach ([$hireA, $hireB] as $hire) {
        $lr = $hire->lorryReceipt->fresh('delivery');
        expect($lr->delivery?->memo_number)->toBe('MEMO-9001')
            ->and($lr->delivery?->status)->toBe(BrokerDeliveryStatus::Delivered)
            ->and($lr->delivery?->delivery_date?->toDateString())->toBe(now()->subDay()->toDateString())
            ->and($lr->status)->toBe(LrStatus::Delivered);
    }

    $eligible = app(BrokerInvoiceService::class)->listEligibleLorryReceipts($ctx['company'], $ctx['branch'], $customer->id);
    expect($eligible['with_memo']->pluck('id')->all())
        ->toEqualCanonicalizing([$hireA->lorry_receipt_id, $hireB->lorry_receipt_id]);
});

test('memo assignment rejects a bilty that already has a memo and keeps the original', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    $service = app(BrokerDeliveryService::class);

    $service->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => 'MEMO-FIRST',
    ]);

    expect(fn () => $service->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => 'MEMO-SECOND',
    ]))->toThrow(BrokerConflictException::class);

    expect($hire->lorryReceipt->fresh('delivery')->delivery?->memo_number)->toBe('MEMO-FIRST');
});

test('a memo number belongs to one customer', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hireA = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hireA->lorryReceipt, $ctx);
    $hireB = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hireB->lorryReceipt, $ctx);
    $service = app(BrokerDeliveryService::class);

    $service->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hireA->lorry_receipt_id],
        'memo_number' => 'MEMO-SHARED',
    ]);

    expect(fn () => $service->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hireB->lorry_receipt_id],
        'memo_number' => 'MEMO-SHARED',
    ]))->toThrow(ValidationException::class, 'This Memo / POD number is already used by another customer.');
});

test('memo date cannot be in the future', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);

    expect(fn () => app(BrokerDeliveryService::class)->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => 'MEMO-FUTURE',
        'memo_date' => now()->addDays(2)->toDateString(),
    ]))->toThrow(ValidationException::class, 'The memo date cannot be in the future.');

    expect($hire->lorryReceipt->fresh()->status)->toBe(LrStatus::InProgress);
});

test('memo date of today in the company timezone is accepted late in the evening', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    $timezone = $ctx['company']->timezone ?: 'Asia/Kolkata';
    $this->travelTo(CarbonImmutable::parse('2026-11-27 23:30', $timezone));

    app(BrokerDeliveryService::class)->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hire->lorry_receipt_id],
        'memo_number' => 'MEMO-LATE',
        'memo_date' => '2026-11-27',
    ]);

    expect($hire->lorryReceipt->fresh('delivery')->delivery?->delivery_date?->toDateString())->toBe('2026-11-27');
});

test('memo bulk assign rejects different billing customers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hireA = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hireA->lorryReceipt, $ctx);

    $hireB = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hireB->lorryReceipt, $ctx);

    expect(fn () => app(BrokerDeliveryService::class)->assignMemoPod($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_ids' => [$hireA->lorry_receipt_id, $hireB->lorry_receipt_id],
        'memo_number' => 'MEMO-X',
    ]))->toThrow(BrokerConflictException::class);
});

test('record delivery marks LR delivered', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    brokerSetBillingCustomer($hire->lorryReceipt, $ctx);
    $lr = $hire->lorryReceipt;

    app(BrokerDeliveryService::class)->recordDelivery($lr, $ctx['member'], [
        'delivery_date' => now()->toDateString(),
    ]);

    $lr->refresh();
    expect($lr->status)->toBe(LrStatus::Delivered)
        ->and($lr->delivered_at)->not->toBeNull();
});

test('detention increases net payable and misc expense decreases it', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $calc = app(LorryHireCalculationService::class);
    $base = $calc->summarize($hire);

    app(BrokerAdjustmentService::class)->createDetention($hire, $ctx['member'], ['amount' => '500.00']);
    $hire->refresh();
    $afterDetention = $calc->summarize($hire);
    expect(bccomp($afterDetention['net_payable'], $base['net_payable'], 2))->toBe(1);

    app(BrokerAdjustmentService::class)->createMiscExpense($hire, $ctx['member'], ['amount' => '200.00']);
    $hire->refresh();
    $afterMisc = $calc->summarize($hire);
    expect(bccomp($afterMisc['net_payable'], $afterDetention['net_payable'], 2))->toBe(-1);
});

test('unloading lowers variable freight without changing tds', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Steel coils',
        'package_count' => 1,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::PerMt->value,
        'freight_rate' => 1000,
    ]]);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]);

    $tdsBefore = $hire->tds_amount;
    $freightBefore = $hire->freight_total;

    app(BrokerAdjustmentService::class)->createUnloadingWeight($hire, $ctx['member'], [
        'new_weight' => '80',
    ]);

    $hire->refresh();
    expect((float) $hire->freight_total)->toBeLessThan((float) $freightBefore)
        ->and($hire->tds_amount)->toBe($tdsBefore);
});

test('fixed amount freight unchanged on unloading', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $freightBefore = $hire->freight_total;

    app(BrokerAdjustmentService::class)->createUnloadingWeight($hire, $ctx['member'], [
        'new_weight' => '50',
    ]);

    $hire->refresh();
    expect($hire->freight_total)->toBe($freightBefore);
});

test('shortage reduces net payable', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $calc = app(LorryHireCalculationService::class);
    $base = $calc->summarize($hire);

    app(BrokerAdjustmentService::class)->createShortage($hire, $ctx['member'], [
        'quantity' => '2',
        'rate' => '100',
    ]);

    $hire->refresh();
    $after = $calc->summarize($hire);
    expect(bccomp($after['net_payable'], $base['net_payable'], 2))->toBe(-1);
});

test('second unloading weight replaces the first active entry', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Bulk',
        'package_count' => 1,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::PerMt->value,
        'freight_rate' => 1000,
    ]]);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]);

    app(BrokerAdjustmentService::class)->createUnloadingWeight($hire, $ctx['member'], ['new_weight' => '90']);
    app(BrokerAdjustmentService::class)->createUnloadingWeight($hire, $ctx['member'], ['new_weight' => '75']);

    $count = BrokerAdjustment::query()
        ->where('lorry_hire_id', $hire->id)
        ->where('type', BrokerAdjustmentType::UnloadingWeight->value)
        ->count();

    expect($count)->toBe(1);

    $active = BrokerAdjustment::query()
        ->where('lorry_hire_id', $hire->id)
        ->where('type', BrokerAdjustmentType::UnloadingWeight->value)
        ->whereNull('deleted_at')
        ->first();

    expect($active?->new_weight)->toBe('75.000');
});

test('duplicate detention via standalone store is rejected', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    expect(fn () => app(BrokerAdjustmentService::class)->store($hire, $ctx['member'], [
        'type' => BrokerAdjustmentType::Detention->value,
        'amount' => '100',
    ]))->toThrow(ValidationException::class);
});
