<?php

use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Freight\FreightCalculator;
use App\Domains\Consignment\LorryReceiptService;
use App\Models\CustomerWebSession;
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
        ->and($lr->from_text)->toBe('Mumbai City, Maharashtra')
        ->and($lr->to_text)->toBe('Pune, Maharashtra');
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

test('lorry hire always uses the bilty intended payee and ignores a client payee', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $other = brokerCreateTransporterAndMarketVehicle($ctx);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'transporter_party_id' => $other['transporter']->id,
        'goods_lines' => brokerGoodsPayloadFromLr($lr),
    ]);

    expect($hire->transporter_party_id)->toBe($lr->intended_payee_party_id)
        ->and($hire->transporter_party_id)->not->toBe($other['transporter']->id);
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

test('historical weight_kg semantics are preserved when client sends weight_mt on update', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'package_count' => 10,
        'actual_weight' => 20,
        'charged_weight' => 20,
        'freight_rate_type' => FreightRateType::PerChargedWeight->value,
        'rate_semantics' => FreightRateSemantics::WeightKg->value,
        'freight_rate' => 4,
    ]]);

    $row = $lr->goodsRows->first();
    expect($row->rate_semantics)->toBe(FreightRateSemantics::WeightKg)
        ->and($row->freight_amount)->toBe('80.00');

    $updated = app(LorryReceiptService::class)->update($lr->fresh(), $ctx['member'], [
        'workflow_context' => 'broker',
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'intended_payee_type' => $lr->intended_payee_type,
        'intended_payee_party_id' => $lr->intended_payee_party_id,
        'consignor_party_id' => $lr->consignor_party_id,
        'consignee_party_id' => $lr->consignee_party_id,
        'vehicle_id' => $lr->vehicle_id,
        'lr_date' => $lr->lr_date?->toDateString(),
        'goods' => [[
            'id' => $row->id,
            'goods_type_id' => $row->goods_type_id,
            'package_type_id' => $row->package_type_id,
            'package_count' => 10,
            'actual_weight' => 20,
            'charged_weight' => 20,
            'freight_rate_type' => FreightRateType::PerChargedWeight->value,
            // Hostile / mistaken client payload must not rewrite historical semantics.
            'rate_semantics' => FreightRateSemantics::WeightMt->value,
            'freight_rate' => 4,
        ]],
    ]);

    $fresh = $updated->goodsRows->first();
    expect($fresh->rate_semantics)->toBe(FreightRateSemantics::WeightKg)
        ->and($fresh->freight_amount)->toBe('80.00')
        ->and($updated->freight_total)->toBe('80.00');
});

test('branch workspace selection after login does not return csrf 419', function () {
    $database = (string) config('database.connections.'.config('database.default').'.database');

    expect(config('session.driver'))->toBe('array')
        // Disposable test DBs only — never the live local `nexus` application database.
        ->and($database)->not->toBe('nexus')
        ->and($database === 'nexus_test' || str_contains($database, 'test'))->toBeTrue();

    $ctx = brokerWorkspace();

    if (auth()->check()) {
        auth()->logout();
    }
    test()->flushSession();
    CustomerWebSession::query()->where('user_id', $ctx['member']->id)->delete();

    $login = test()->post(route('login.store'), [
        'email' => $ctx['member']->email,
        'password' => 'member-secret',
    ]);
    $login->assertRedirect();
    test()->assertAuthenticatedAs($ctx['member']);
    foreach ($login->headers->getCookies() as $cookie) {
        test()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    foreach (range(1, 3) as $_) {
        enterBranchWorkspace($ctx['company'], $ctx['branch'])->assertRedirect();
    }
});

test('lorry hire inherits bilty rate_semantics for transporter freight math', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'package_count' => 10,
        'actual_weight' => 25000,
        'charged_weight' => 25000,
        'freight_rate_type' => FreightRateType::PerChargedWeight->value,
        'rate_semantics' => FreightRateSemantics::WeightMt->value,
        'freight_rate' => 2000,
    ]]);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows->first()->id,
            'rate_type' => FreightRateType::PerChargedWeight->value,
            'rate' => 1800,
            'loading_weight' => 25000,
        ]],
    ]);

    $line = $hire->goodsLines->first();
    expect($line->rate_semantics)->toBe(FreightRateSemantics::WeightMt)
        // 1800 ₹/MT × 25 MT = 45000
        ->and($line->freight_amount)->toBe('45000.00');
});

test('historical hire unloading recalc retains weight_kg semantics', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'package_count' => 10,
        'actual_weight' => 100,
        'charged_weight' => 100,
        'freight_rate_type' => FreightRateType::PerActualWeight->value,
        'rate_semantics' => FreightRateSemantics::WeightKg->value,
        'freight_rate' => 5,
    ]]);

    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows->first()->id,
            'rate_type' => FreightRateType::PerActualWeight->value,
            'rate' => 5,
            'loading_weight' => 100,
        ]],
    ]);

    expect($hire->goodsLines->first()->freight_amount)->toBe('500.00');

    $total = app(LorryHireCalculationService::class)
        ->recalculateFreightForUnloading($hire->fresh(), '80');

    expect($total)->toBe('400.00')
        ->and($hire->fresh()->goodsLines->first()->rate_semantics)->toBe(FreightRateSemantics::WeightKg)
        ->and($hire->fresh()->goodsLines->first()->freight_amount)->toBe('400.00');
});

test('editing unrelated bilty text does not rewrite historical freight amount', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'package_count' => 10,
        'actual_weight' => 20,
        'charged_weight' => 20,
        'freight_rate_type' => FreightRateType::PerChargedWeight->value,
        'rate_semantics' => FreightRateSemantics::WeightKg->value,
        'freight_rate' => 4,
        'goods_description' => 'Original cargo',
    ]]);

    $row = $lr->goodsRows->first();
    expect($row->freight_amount)->toBe('80.00');

    $updated = app(LorryReceiptService::class)->update($lr->fresh(), $ctx['member'], [
        'workflow_context' => 'broker',
        'billing_customer_party_id' => $lr->billing_customer_party_id,
        'intended_payee_type' => $lr->intended_payee_type,
        'intended_payee_party_id' => $lr->intended_payee_party_id,
        'consignor_party_id' => $lr->consignor_party_id,
        'consignee_party_id' => $lr->consignee_party_id,
        'vehicle_id' => $lr->vehicle_id,
        'lr_date' => $lr->lr_date?->toDateString(),
        'remarks' => 'Non-financial remark only',
        'goods' => [[
            'id' => $row->id,
            'goods_type_id' => $row->goods_type_id,
            'package_type_id' => $row->package_type_id,
            'goods_description' => 'Updated description only',
            'package_count' => 10,
            'actual_weight' => 20,
            'charged_weight' => 20,
            'freight_rate_type' => FreightRateType::PerChargedWeight->value,
            'rate_semantics' => FreightRateSemantics::WeightKg->value,
            'freight_rate' => 4,
        ]],
    ]);

    expect($updated->goodsRows->first()->freight_amount)->toBe('80.00')
        ->and($updated->goodsRows->first()->rate_semantics)->toBe(FreightRateSemantics::WeightKg)
        ->and($updated->goodsRows->first()->goods_description)->toBe('Updated description only')
        ->and($updated->freight_total)->toBe('80.00');
});

test('duplicate hire http recovery preserves create-and-record-voucher destination', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    brokerGrantLorryHirePermissions();
    brokerGrantVoucherPermissions();

    $lr = brokerCreateBrokerLr($ctx);
    $first = app(LorryHireService::class)->createFromLorryReceipt(
        $lr,
        $ctx['member'],
        brokerHirePayloadFromLr($lr),
    );

    enterBranchWorkspace($ctx['company'], $ctx['branch']);

    $response = $this->actingAs($ctx['member'])
        ->post(route('branch.broker.lorry-hires.store-from-lr', [$lr]), array_merge(brokerHirePayloadFromLr($lr), [
            'redirect_to_voucher' => true,
        ]));

    $response->assertRedirect(route('branch.broker.lorry-hires.show', [$first, 'pay' => 1]));
    expect(LorryHire::query()->where('lorry_receipt_id', $lr->id)->whereNull('deleted_at')->count())->toBe(1);
});
