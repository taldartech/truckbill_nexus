<?php

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\LorryReceiptOperationalStatusService;
use App\Domains\Consignment\LorryReceiptService;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\OwnFleetBiltyTripOrchestrator;
use App\Domains\OwnFleet\Trip\TripService;
use App\Models\LorryReceipt;
use App\Models\TransporterVoucher;
use App\Models\Trip;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Database\Seeders\TdsReferenceRulesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/BrokerLorryHireHelpers.php';
require_once __DIR__.'/../Support/OwnFleetTripHelpers.php';

beforeEach(function () {
    TenantContextHolder::clear();
    Cache::flush();
    (new RbacSeeder)->run();
    (new SubscriptionCatalogueSeeder)->run();
    (new TdsReferenceRulesSeeder)->run();
});

test('own vehicle bilty create establishes internal trip atomically', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $lr = tripCreateOwnFleetLr($ctx);
    $trip = app(TripService::class)->createFromLorryReceipt($lr, $ctx['member'], [
        'vehicle_id' => $ctx['vehicle']->id,
    ]);

    expect($lr->fresh()->status)->toBe(LrStatus::Pending)
        ->and($trip->status)->toBe(TripStatus::Pending)
        ->and(Trip::query()->where('lorry_receipt_id', $lr->id)->count())->toBe(1);
});

test('ownfleet bilty trip orchestrator creates lr and trip together', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $customer = tripCreateBillingCustomer($ctx);
    $payload = [
        'workflow_context' => 'own_fleet',
        'vehicle_id' => $ctx['vehicle']->id,
        'billing_customer_party_id' => $customer->id,
        'from_station_id' => $ctx['fromStation']->id,
        'to_station_id' => $ctx['toStation']->id,
        'goods' => [[
            'goods_type_id' => $ctx['goodsType']->id,
            'package_type_id' => $ctx['packageType']->id,
            'goods_description' => 'Cement',
            'package_count' => 10,
            'actual_weight' => 10,
            'charged_weight' => 10,
            'freight_rate_type' => 'per_charged_weight',
            'rate_semantics' => 'weight_mt',
            'freight_rate' => 1000,
        ]],
    ];

    $created = app(OwnFleetBiltyTripOrchestrator::class)->create(
        $ctx['company'],
        $ctx['branch'],
        $ctx['member'],
        $payload,
        true,
    );

    expect($created['lorry_receipt'])->toBeInstanceOf(LorryReceipt::class)
        ->and($created['trip'])->not->toBeNull()
        ->and($created['trip']->lorry_receipt_id)->toBe($created['lorry_receipt']->id);
});

test('own vehicle status pending to in progress updates trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt;

    $updated = app(LorryReceiptOperationalStatusService::class)->changeStatus(
        $lr,
        $ctx['member'],
        ['status' => LrStatus::InProgress->value, 'starting_odometer' => '100'],
    );

    expect($updated->status)->toBe(LrStatus::InProgress)
        ->and($trip->fresh()->status)->toBe(TripStatus::InProgress);
});

test('broker hire creation moves bilty to in progress', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $hire = brokerCreateLorryHire($ctx);
    $lr = LorryReceipt::query()->findOrFail($hire->lorry_receipt_id);

    expect($lr->status)->toBe(LrStatus::InProgress);
});

test('hire summarize splits remaining payable for in progress vouchers', function () {
    $ctx = brokerWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);
    $hire = brokerCreateLorryHire($ctx);

    $hire->forceFill([
        'freight_total' => '50000.00',
        'tds_amount' => '0.00',
        'outstanding_amount' => '50000.00',
        'paid_amount' => '0.00',
    ])->save();

    TransporterVoucher::query()->create([
        'id' => (string) Str::uuid(),
        'company_id' => $hire->company_id,
        'branch_id' => $hire->branch_id,
        'lorry_hire_id' => $hire->id,
        'transporter_party_id' => $hire->transporter_party_id,
        'vehicle_id' => $hire->vehicle_id,
        'voucher_number' => 'TV-TEST-1',
        'voucher_date' => now()->toDateString(),
        'payment_type' => VoucherPaymentType::Advance,
        'payment_mode' => VoucherPaymentMode::Cash,
        'amount' => '20000.00',
        'status' => VoucherStatus::InProgress,
        'created_by_user_id' => $ctx['member']->id,
    ]);

    $summary = app(LorryHireCalculationService::class)->summarize(
        $hire->fresh(['adjustments', 'vouchers', 'bookBalanceEntries']),
    );

    expect($summary['reserved_voucher_amount'])->toBe('20000.00')
        ->and($summary['remaining_payable'])->toBe('30000.00')
        ->and($summary['balance_due'])->toBe($summary['outstanding_amount']);
});

test('pending own vehicle bilty soft deletes draft trip', function () {
    $ctx = tripWorkspace();
    tripActivate($ctx['company'], $ctx['branch']);

    $trip = tripCreatePendingTrip($ctx);
    $lr = $trip->lorryReceipt;

    app(LorryReceiptService::class)->softDelete(
        $lr,
        $ctx['member'],
        'Delete draft own bilty',
    );

    expect($lr->fresh()->trashed())->toBeTrue()
        ->and($trip->fresh()->trashed())->toBeTrue();
});
