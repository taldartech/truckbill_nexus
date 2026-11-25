<?php

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Challan\PartloadChallanTotals;
use App\Domains\Partload\Delivery\PartloadDeliveryReceiptService;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Inward\PartloadInwardService;
use App\Models\LorryReceipt;
use App\Models\PartloadChallanLine;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Validation\ValidationException;

require_once dirname(__DIR__, 2).'/Support/PartloadHelpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
});

it('computes challan TOTAL using Rules §12 formula', function (): void {
    expect(PartloadChallanTotals::computeTotal([
        'to_pay_amount' => 1000,
        'crossing_freight' => 100,
        'labour_charge' => 50,
        'kat' => 20,
        'home_delivery_charge' => 30,
        'truck_freight' => 200,
        'cash_payment' => 40,
        'cash_received' => 10,
    ]))->toBe('830.00');
});

it('creates dispatches inward and delivery receipt for consolidated partload bilties', function (): void {
    $ctx = partloadWorkspace();
    $a = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $b = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::Paid);

    $challans = app(PartloadChallanService::class);
    $challan = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$a->id, $b->id],
        'truck_freight' => 500,
        'labour_charge' => 50,
        'dispatch' => true,
    ]);

    expect($challan->status)->toBe(PartloadChallanStatus::Dispatched)
        ->and($challan->lines)->toHaveCount(2)
        ->and((float) $challan->to_pay_amount)->toBe(1500.0)
        ->and((float) $challan->paid_amount)->toBe(1500.0)
        ->and($a->fresh()->status)->toBe(LrStatus::InProgress)
        ->and($b->fresh()->status)->toBe(LrStatus::InProgress);

    expect(fn () => $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$a->id],
    ]))->toThrow(ValidationException::class);

    $c = partloadCreateBilty($ctx, PartloadFreightPaymentTerm::ToPay);
    $draft = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$c->id],
    ]);
    expect(fn () => $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$c->id],
    ]))->toThrow(PartloadConflictException::class);
    expect($draft->status)->toBe(PartloadChallanStatus::Draft);

    $inward = app(PartloadInwardService::class)->createFromChallan($ctx['company'], $ctx['branch'], $ctx['member'], [
        'partload_challan_id' => $challan->id,
    ]);

    expect($inward->lines)->toHaveCount(2)
        ->and($challan->fresh()->status)->toBe(PartloadChallanStatus::Received);

    $deliveryLine = $inward->lines->first();
    $dr = app(PartloadDeliveryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'lorry_receipt_id' => $deliveryLine->lorry_receipt_id,
        'partload_inward_line_id' => $deliveryLine->id,
        'payment_mode' => PartloadDeliveryPaymentMode::Due->value,
        'payment_due_from_party_id' => $ctx['customer']->id,
    ]);

    expect($dr->dr_number)->not->toBeEmpty()
        ->and(LorryReceipt::query()->find($deliveryLine->lorry_receipt_id)?->status)->toBe(LrStatus::Delivered);
});

it('cancels a draft challan and releases bilty loading locks', function (): void {
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

    expect($challan->status)->toBe(PartloadChallanStatus::Draft);

    $challans->cancel($challan, $ctx['member'], 'Operator cancelled before dispatch');

    expect($challan->fresh()->status)->toBe(PartloadChallanStatus::Cancelled)
        ->and(
            PartloadChallanLine::query()
                ->where('partload_challan_id', $challan->id)
                ->whereNull('deleted_at')
                ->count(),
        )->toBe(0);

    $again = $challans->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'vehicle_id' => $ctx['vehicle']->id,
        'driver_party_id' => $ctx['primaryDriver']->id,
        'lorry_receipt_ids' => [$bilty->id],
    ]);

    expect($again->id)->not->toBe($challan->id)
        ->and($again->lines)->toHaveCount(1);
});
