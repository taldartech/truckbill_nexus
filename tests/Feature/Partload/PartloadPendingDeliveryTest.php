<?php

use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Delivery\PartloadDeliveryReceiptService;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Inward\PartloadInwardService;
use App\Domains\Partload\Reports\PartloadPendingDeliveryService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;

require_once dirname(__DIR__, 2).'/Support/PartloadHelpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
});

it('lists delivery disposition inward lines without a delivery receipt', function (): void {
    $ctx = partloadWorkspace();
    $a = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $b = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::Paid);

    $challan = app(PartloadChallanService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$a->id, $b->id],
        'dispatch' => true,
    ]);

    $inward = app(PartloadInwardService::class)->createFromChallan($ctx['company'], $ctx['branch'], $ctx['member'], [
        'partload_challan_id' => $challan->id,
    ]);

    $pending = app(PartloadPendingDeliveryService::class)->snapshot(
        company: $ctx['company'],
        branch: $ctx['branch'],
        actor: $ctx['member'],
    );

    expect($pending['totals']['count'])->toBe(2);

    $first = $inward->lines->first();
    app(PartloadDeliveryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_id' => $first->lorry_receipt_id,
        'partload_inward_line_id' => $first->id,
        'payment_mode' => PartloadDeliveryPaymentMode::Due->value,
        'payment_due_from_party_id' => $ctx['customer']->id,
    ]);

    $remaining = app(PartloadPendingDeliveryService::class)->snapshot(
        company: $ctx['company'],
        branch: $ctx['branch'],
        actor: $ctx['member'],
    );

    expect($remaining['totals']['count'])->toBe(1)
        ->and($remaining['rows'][0]['id'])->not->toBe($first->id);
});
