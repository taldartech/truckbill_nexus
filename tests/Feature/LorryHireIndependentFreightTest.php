<?php

use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Support\FreightRateTypeCatalog;
use App\Models\LorryHire;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';
require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../Support/RbacPersonaHelpers.php';

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

test('transporter freight rate and loading weight are independent of customer bilty freight', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => null,
        'package_count' => 1,
        'actual_weight' => 8000,
        'charged_weight' => 8000,
        'freight_rate_type' => FreightRateType::PerMt->value,
        'freight_rate' => 9000,
        'goods_value' => 100000,
    ]]);

    expect($lr->freight_total)->toBe('72000.00');

    $row = $lr->goodsRows->first();
    $hire = app(LorryHireService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'goods_lines' => [[
            'lr_goods_row_id' => $row->id,
            'rate_type' => FreightRateType::PerMt->value,
            'rate' => '7500',
        ]],
    ]);

    $hire->load(['goodsLines', 'tdsSnapshot']);
    $line = $hire->goodsLines->first();

    expect($line->rate)->toBe('7500.0000')
        ->and($line->loading_weight)->toBe('8000.000')
        ->and($line->freight_amount)->toBe('60000.00')
        ->and($hire->freight_total)->toBe('60000.00')
        ->and($hire->tdsSnapshot)->not->toBeNull();

    $netPayable = bcsub((string) $hire->freight_total, (string) $hire->tds_amount, 2);
    expect((string) $hire->outstanding_amount)->toBe($netPayable);

    $lr->refresh();
    expect($lr->freight_total)->toBe('72000.00')
        ->and($lr->goodsRows->first()->freight_rate)->toBe('9000.0000')
        ->and($lr->goodsRows->first()->actual_weight)->toBe('8000.000');
});

test('create form presents inherited bilty summary, payee and catalog rate types', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    rbacHttpAs($ctx['member'], $ctx);

    $this->get(route('branch.broker.lorry-hires.create-from-lr', [$lr]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/lorry-hires/create')
            // Broker route is derived from party city/state (PO-LGC-018).
            ->where('lorryReceipt.loading_station', 'Mumbai City, Maharashtra')
            ->where('lorryReceipt.unloading_station', 'Pune, Maharashtra')
            ->where('lorryReceipt.customer_name', 'Broker Billing Customer')
            ->where('payee.id', $lr->intended_payee_party_id)
            ->where('payeeBlockedReason', null)
            ->has('goodsRows.0.id')
            ->has('goodsRows.0.rate_semantics')
            ->where('rateTypeOptions', FreightRateTypeCatalog::inertiaOptions())
            ->where('defaults.rate_type', 'per_charged_weight')
            ->has('defaults.hire_date')
            ->missing('transporterOptions')
            ->missing('payeeRequired'));
});

test('create form blocks hire when the bilty has no resolvable payee', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $lr->forceFill(['intended_payee_party_id' => null])->save();

    rbacHttpAs($ctx['member'], $ctx);

    $this->get(route('branch.broker.lorry-hires.create-from-lr', [$lr]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('payee', null)
            ->where('tdsPreview', null)
            ->where('payeeBlockedReason', fn ($reason) => str_contains((string) $reason, 'no intended Payee'))
            ->where('urls.lr_edit', route('branch.lorry-receipts.edit', [$lr], absolute: false)));

    rbacHttpAs($ctx['member'], $ctx);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('branch.broker.lorry-hires.store-from-lr', [$lr]), [
            'hire_date' => now()->toDateString(),
            'goods_lines' => brokerGoodsPayloadFromLr($lr),
        ])
        ->assertSessionHasErrors('payee');

    expect(LorryHire::query()->where('lorry_receipt_id', $lr->id)->exists())->toBeFalse();
});

test('store derives loading weight and payee from the bilty and ignores client values', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Broker cartons',
        'package_count' => 10,
        'actual_weight' => 2000,
        'charged_weight' => 2500,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 7500,
    ]]);
    $rowId = $lr->goodsRows->first()->id;

    rbacHttpAs($ctx['member'], $ctx);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('branch.broker.lorry-hires.store-from-lr', [$lr]), [
            'hire_date' => '2026-11-20',
            // Forged values: only the Bilty payee and Bilty weights are authoritative.
            'transporter_party_id' => $lr->consignor_party_id,
            'goods_lines' => [[
                'lr_goods_row_id' => $rowId,
                'rate_type' => FreightRateType::PerActualWeight->value,
                'rate' => '1000',
                'loading_weight' => '9999',
            ]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $hire = LorryHire::query()->where('lorry_receipt_id', $lr->id)->with('goodsLines')->firstOrFail();
    $line = $hire->goodsLines->first();

    expect($hire->transporter_party_id)->toBe($lr->intended_payee_party_id)
        ->and($hire->hire_date->toDateString())->toBe('2026-11-20')
        // Per Actual Weight → actual weight basis (2000 KG = 2 MT × ₹1000).
        ->and($line->loading_weight)->toBe('2000.000')
        ->and($line->freight_amount)->toBe('2000.00');
});

test('store rejects rate types outside the catalog and requires hire date', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $rowId = $lr->goodsRows->first()->id;

    rbacHttpAs($ctx['member'], $ctx);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('branch.broker.lorry-hires.store-from-lr', [$lr]), [
            'goods_lines' => [[
                'lr_goods_row_id' => $rowId,
                'rate_type' => FreightRateType::PerMt->value,
                'rate' => '100',
            ]],
        ])
        ->assertSessionHasErrors(['hire_date', 'goods_lines.0.rate_type']);

    expect(LorryHire::query()->where('lorry_receipt_id', $lr->id)->exists())->toBeFalse();
});

test('repeat submission for a bilty with an active hire redirects to the existing hire', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    rbacHttpAs($ctx['member'], $ctx);

    $payload = [
        'hire_date' => now()->toDateString(),
        'goods_lines' => [[
            'lr_goods_row_id' => $lr->goodsRows->first()->id,
            'rate_type' => FreightRateType::FixedAmount->value,
            'rate' => '5000',
        ]],
    ];
    $url = route('branch.broker.lorry-hires.store-from-lr', [$lr]);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post($url, $payload)
        ->assertSessionHasNoErrors();

    $hire = LorryHire::query()->where('lorry_receipt_id', $lr->id)->firstOrFail();

    rbacHttpAs($ctx['member'], $ctx);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post($url, $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('branch.broker.lorry-hires.show', [$hire]));

    expect(LorryHire::query()->where('lorry_receipt_id', $lr->id)->count())->toBe(1);
});

test('store endpoint persists independent transporter rate with bilty-derived loading weight', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    // Hire loading weight inherits Bilty charged weight (not client-editable).
    $lr = brokerCreateBrokerLr($ctx, [[
        'goods_type_id' => $ctx['goodsType']->id,
        'package_type_id' => $ctx['packageType']->id,
        'goods_description' => 'Broker cartons',
        'package_count' => 10,
        'actual_weight' => 2500,
        'charged_weight' => 2500,
        'freight_rate_type' => FreightRateType::FixedAmount->value,
        'freight_rate' => 7500,
    ]]);
    $rowId = $lr->goodsRows->first()->id;

    rbacHttpAs($ctx['member'], $ctx);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('branch.broker.lorry-hires.store-from-lr', [$lr]), [
            'hire_date' => now()->toDateString(),
            'goods_lines' => [[
                'lr_goods_row_id' => $rowId,
                // PO-LGC-018: Per Charged Weight × MT (2500 KG = 2.5 MT × ₹5000 = ₹12500).
                'rate_type' => FreightRateType::PerChargedWeight->value,
                'rate' => '5000',
            ]],
            'remarks' => 'Independent transporter freight',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $hire = LorryHire::query()
        ->where('lorry_receipt_id', $lr->id)
        ->whereNull('deleted_at')
        ->with('goodsLines')
        ->first();

    expect($hire)->not->toBeNull()
        ->and($hire->goodsLines->first()->loading_weight)->toBe('2500.000')
        ->and($hire->goodsLines->first()->rate)->toBe('5000.0000')
        ->and($hire->goodsLines->first()->freight_amount)->toBe('12500.00');
});
