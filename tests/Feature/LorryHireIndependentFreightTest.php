<?php

use App\Domains\Broker\LorryHire\LorryHireService;
use App\Domains\Consignment\Enums\FreightRateType;
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
        'transporter_party_id' => $lr->vehicle?->owner_party_id,
        'goods_lines' => [[
            'lr_goods_row_id' => $row->id,
            'rate_type' => FreightRateType::PerMt->value,
            'rate' => '7500',
            'loading_weight' => '8000',
            'is_fixed_amount' => false,
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

test('create form presents broker free-text route labels', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);

    rbacHttpAs($ctx['member'], $ctx);

    $this->get(route('branch.broker.lorry-hires.create-from-lr', [$lr]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('broker/lorry-hires/create')
            // Broker route is derived from party city/state (PO-LGC-018).
            ->where('lorryReceipt.loading_station', 'Mumbai, MH')
            ->where('lorryReceipt.unloading_station', 'Pune, MH')
            ->has('goodsRows.0.id')
            ->has('rateTypeOptions')
            ->has('transporterOptions')
            ->where('payeeRequired', true));
});

test('store endpoint validates loading_weight and persists independent transporter rate', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $lr = brokerCreateBrokerLr($ctx);
    $lr->loadMissing('vehicle');
    $rowId = $lr->goodsRows->first()->id;

    rbacHttpAs($ctx['member'], $ctx);

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('branch.broker.lorry-hires.store-from-lr', [$lr]), [
            'transporter_party_id' => $lr->vehicle?->owner_party_id,
            'goods_lines' => [[
                'lr_goods_row_id' => $rowId,
                // PO-LGC-018: Per Charged Weight × MT (2500 KG = 2.5 MT × ₹5000 = ₹12500).
                'rate_type' => FreightRateType::PerChargedWeight->value,
                'rate' => '5000',
                'loading_weight' => '2500',
                'is_fixed_amount' => false,
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
