<?php

use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyService;
use App\Domains\Master\Vehicle\Enums\VehicleOwnership;
use App\Domains\Partload\Challan\PartloadChallanService;
use App\Domains\Partload\Challan\PartloadChallanTotals;
use App\Domains\Partload\Delivery\PartloadDeliveryReceiptService;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Enums\PartloadFreightPaymentTerm;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Inward\PartloadInwardService;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\LorryReceipt;
use App\Models\PartloadChallanLine;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once dirname(__DIR__, 2).'/Support/OwnFleetTripHelpers.php';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    (new SubscriptionCatalogueSeeder)->run();
});

function partloadGrantPermissions(): void
{
    foreach ([
        PermissionCodes::LR_VIEW,
        PermissionCodes::LR_CREATE,
        PermissionCodes::PARTLOAD_CHALLAN_VIEW,
        PermissionCodes::PARTLOAD_CHALLAN_CREATE,
        PermissionCodes::PARTLOAD_CHALLAN_DISPATCH,
        PermissionCodes::PARTLOAD_CHALLAN_CANCEL,
        PermissionCodes::PARTLOAD_INWARD_VIEW,
        PermissionCodes::PARTLOAD_INWARD_CREATE,
        PermissionCodes::PARTLOAD_DR_VIEW,
        PermissionCodes::PARTLOAD_DR_CREATE,
    ] as $code) {
        tripGrant('customer.admin', $code);
    }
}

/**
 * @return array<string, mixed>
 */
function partloadWorkspace(): array
{
    $ctx = tripWorkspace([SubscriptionModule::Partload->value]);
    partloadGrantPermissions();

    $customer = app(PartyService::class)->create(
        actor: $ctx['owner'],
        company: $ctx['company'],
        branch: null,
        attributes: [
            'name' => 'Partload Customer '.Str::lower(Str::random(4)),
            'contact_number' => '9800099001',
            'state' => 'MH',
            'city' => 'Mumbai',
            'is_gst_applicable' => false,
        ],
        roles: [
            ['role' => PartyRoleType::Customer->value],
            ['role' => PartyRoleType::Consignor->value],
            ['role' => PartyRoleType::Consignee->value],
        ],
        remark: 'Partload customer',
    );

    $ctx['vehicle']->forceFill([
        'ownership' => VehicleOwnership::Own,
        'primary_driver_party_id' => $ctx['primaryDriver']->id,
    ])->save();

    $ctx['customer'] = $customer;

    return $ctx;
}

function partloadCreateBilty(array $ctx, PartloadFreightPaymentTerm $term = PartloadFreightPaymentTerm::ToPay): LorryReceipt
{
    return app(LorryReceiptService::class)->create($ctx['company'], $ctx['branch'], $ctx['member'], [
        'workflow_context' => LrWorkflowContext::Partload->value,
        'lr_date' => now()->toDateString(),
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'billing_customer_party_id' => $ctx['customer']->id,
        'consignor_party_id' => $ctx['customer']->id,
        'consignee_party_id' => $ctx['customer']->id,
        'freight_payment_term' => $term->value,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Partload cartons',
            'package_count' => 5,
            'actual_weight' => 1000,
            'charged_weight' => 1000,
            'freight_rate_type' => FreightRateType::FixedAmount->value,
            'freight_rate' => 1500,
            'freight_amount' => 1500,
        ]],
    ]);
}

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
