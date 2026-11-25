<?php

use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Reports\PartloadPendingLoadingService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;

require_once dirname(__DIR__, 2).'/Support/PartloadHelpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
});

it('lists pending partload bilties and excludes loaded ones', function (): void {
    $ctx = partloadWorkspace();
    $pending = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $loaded = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::Paid);

    app(PartloadChallanService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$loaded->id],
    ]);

    $snapshot = app(PartloadPendingLoadingService::class)->snapshot(
        company: $ctx['company'],
        branch: $ctx['branch'],
        actor: $ctx['member'],
    );

    expect($snapshot['totals']['count'])->toBe(1)
        ->and($snapshot['rows'][0]['id'])->toBe($pending->id)
        ->and($snapshot['rows'][0]['packages'])->toBe('5')
        ->and($snapshot['rows'][0]['weight_mt'])->toBe('1.000')
        ->and((float) $snapshot['totals']['freight_total'])->toBe(1500.0);
});

it('filters pending loading by station', function (): void {
    $ctx = partloadWorkspace();
    partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);

    $empty = app(PartloadPendingLoadingService::class)->snapshot(
        company: $ctx['company'],
        branch: $ctx['branch'],
        actor: $ctx['member'],
        fromStationId: $ctx['toStation']->id,
    );

    expect($empty['totals']['count'])->toBe(0);

    $matched = app(PartloadPendingLoadingService::class)->snapshot(
        company: $ctx['company'],
        branch: $ctx['branch'],
        actor: $ctx['member'],
        fromStationId: $ctx['fromStation']->id,
        toStationId: $ctx['toStation']->id,
    );

    expect($matched['totals']['count'])->toBe(1);
});
