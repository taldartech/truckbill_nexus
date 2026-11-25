<?php

use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Enums\PartloadMainChallanStatus;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\MainChallan\PartloadMainChallanService;
use App\Models\PartloadChallan;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;

require_once dirname(__DIR__, 2).'/Support/PartloadHelpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
});

it('consolidates draft challans onto a main challan and cascades dispatch', function (): void {
    $ctx = partloadWorkspace();
    $a = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $b = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::Paid);

    $challans = app(PartloadChallanService::class);
    $challanA = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$a->id],
    ]);
    $challanB = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$b->id],
    ]);

    $mains = app(PartloadMainChallanService::class);
    $main = $mains->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'partload_challan_ids' => [$challanA->id, $challanB->id],
        'truck_freight' => 800,
        'dispatch' => true,
    ]);

    expect($main->status)->toBe(PartloadMainChallanStatus::Dispatched)
        ->and($main->lines)->toHaveCount(2)
        ->and($challanA->fresh()->status)->toBe(PartloadChallanStatus::Dispatched)
        ->and($challanB->fresh()->status)->toBe(PartloadChallanStatus::Dispatched)
        ->and($challanA->fresh()->partload_main_challan_id)->toBe($main->id);

    // Already dispatched via Main — independent dispatch is a no-op, not a conflict.
    expect($challans->dispatch($challanA->fresh(), $ctx['member'])->status)
        ->toBe(PartloadChallanStatus::Dispatched);
});

it('blocks duplicate challan attachment and releases on cancel', function (): void {
    $ctx = partloadWorkspace();
    $bilty = partloadCreateBilty($ctx);
    $challans = app(PartloadChallanService::class);
    $challan = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$bilty->id],
    ]);

    $mains = app(PartloadMainChallanService::class);
    $main = $mains->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'partload_challan_ids' => [$challan->id],
    ]);

    expect($main->status)->toBe(PartloadMainChallanStatus::Draft)
        ->and($challan->fresh()->partload_main_challan_id)->toBe($main->id);

    expect(fn () => $mains->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'partload_challan_ids' => [$challan->id],
    ]))->toThrow(PartloadConflictException::class);

    expect(fn () => $challans->dispatch($challan->fresh(), $ctx['member']))
        ->toThrow(PartloadConflictException::class);

    $mains->cancel($main, $ctx['member'], 'Wrong consolidation');

    expect($main->fresh()->status)->toBe(PartloadMainChallanStatus::Cancelled)
        ->and($challan->fresh()->partload_main_challan_id)->toBeNull()
        ->and(
            PartloadChallan::query()
                ->whereKey($challan->id)
                ->whereNull('partload_main_challan_id')
                ->where('status', PartloadChallanStatus::Draft->value)
                ->exists(),
        )->toBeTrue();
});
