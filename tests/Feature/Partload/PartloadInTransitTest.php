<?php

use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Reports\PartloadInTransitService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;

require_once dirname(__DIR__, 2).'/Support/PartloadHelpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
});

it('lists dispatched challans as in transit and excludes drafts', function (): void {
    $ctx = partloadWorkspace();
    $dispatchedLr = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $draftLr = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::Paid);

    $challans = app(PartloadChallanService::class);
    $dispatched = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$dispatchedLr->id],
        'dispatch' => true,
    ]);

    $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$draftLr->id],
    ]);

    $snapshot = app(PartloadInTransitService::class)->snapshot(
        company: $ctx['company'],
        branch: $ctx['branch'],
        actor: $ctx['member'],
    );

    expect($snapshot['totals']['count'])->toBe(1)
        ->and($snapshot['rows'][0]['id'])->toBe($dispatched->id)
        ->and($snapshot['rows'][0]['line_count'])->toBe(1);
});
