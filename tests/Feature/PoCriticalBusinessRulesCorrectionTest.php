<?php

use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Freight\FreightCalculator;
use App\Domains\Consignment\LorryReceiptService;
use App\Models\LorryHire;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

afterEach(function () {
    TenantContextHolder::clear();
});

test('new bilty rejects missing billing customer', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $market = brokerCreateTransporterAndMarketVehicle($ctx);

    expect(fn () => app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => 'broker',
        'vehicle_id' => $market['vehicle']->id,
        'lr_date' => now()->toDateString(),
        'from_text' => 'Pune',
        'to_text' => 'Kolhapur',
        'intended_payee_type' => 'owner',
        'intended_payee_party_id' => $market['transporter']->id,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'package_count' => 10,
            'actual_weight' => 1000,
            'charged_weight' => 1000,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 1800,
        ]],
    ]))->toThrow(ValidationException::class);
});

test('selected billing customer is persisted and broker route derives from parties', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx);

    expect($lr->billing_customer_party_id)->not->toBeNull()
        ->and($lr->intended_payee_party_id)->not->toBeNull()
        ->and($lr->from_text)->toBe('Mumbai, MH')
        ->and($lr->to_text)->toBe('Pune, MH');
});

test('per charged weight with weight_mt semantics multiplies rate by MT', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'package_count' => 500,
        'actual_weight' => 25000,
        'charged_weight' => 26000,
        'freight_rate_type' => FreightRateType::PerChargedWeight->value,
        'rate_semantics' => FreightRateSemantics::WeightMt->value,
        'freight_rate' => 2500,
    ]]);

    expect($lr->goodsRows->first()->rate_semantics)->toBe(FreightRateSemantics::WeightMt)
        ->and($lr->goodsRows->first()->freight_amount)->toBe('65000.00')
        ->and($lr->freight_total)->toBe('65000.00');
});

test('historical weight_kg semantics preserve kilogram freight math', function () {
    $calc = new FreightCalculator;

    expect($calc->compute(
        FreightRateType::PerChargedWeight,
        '4',
        null,
        null,
        '15',
        FreightRateSemantics::WeightKg,
    ))->toBe('60.00');
});

test('fractional package units are rejected', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    expect(fn () => brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'package_count' => '12.500',
        'actual_weight' => 1000,
        'charged_weight' => 1000,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 1800,
    ]]))->toThrow(ValidationException::class);
});

test('lorry hire inherits bilty intended payee and rejects forged override', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $other = brokerCreateTransporterAndMarketVehicle($ctx);

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $other['transporter']->id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]))->toThrow(ValidationException::class);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $lr->intended_payee_party_id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]);

    expect($hire->transporter_party_id)->toBe($lr->intended_payee_party_id);
});

test('duplicate active hire create is rejected while uniqueness is preserved', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    $first = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], brokerHirePayloadFromLr($lr));

    expect(fn () => app(LorryHireService::class)->createFromLorryReceipt(
        $lr->fresh(),
        $ctx['member'],
        brokerHirePayloadFromLr($lr),
    ))->toThrow(BrokerConflictException::class);

    expect(LorryHire::query()->where('lorry_receipt_id', $lr->id)->whereNull('deleted_at')->count())->toBe(1)
        ->and($first->id)->not->toBeEmpty();
});

test('hire may be created without client transporter_party_id when bilty payee exists', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]);

    expect($hire->transporter_party_id)->toBe($lr->intended_payee_party_id);
});
