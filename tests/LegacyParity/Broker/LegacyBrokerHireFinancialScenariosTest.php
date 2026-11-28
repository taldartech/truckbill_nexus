<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Unit/Services/FreightBookingAmountVerifierTest.php
 * and docs/Truckbill (Broker) - Calculations.md (transporter balance semantics).
 *
 * These scenarios preserve legacy business inputs/outcomes against authoritative Nexus services.
 */

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Models\BrokerAdjustment;
use App\Models\TransporterVoucher;
use Illuminate\Support\Collection;

test('legacy scenario: net payable with positive and negative fixed adjustments', function () {
    $calc = app(LorryHireCalculationService::class);

    /** @var Collection<int, BrokerAdjustment> $adjustments */
    $adjustments = new Collection([
        new BrokerAdjustment(['type' => BrokerAdjustmentType::Detention, 'amount' => '500.00']),
        new BrokerAdjustment(['type' => BrokerAdjustmentType::Shortage, 'amount' => '22050.00']),
    ]);

    $net = $calc->netPayable('41054.25', '445.00', '0.00', $adjustments);

    expect($net)->toBe('19059.25');
});

test('legacy scenario: outstanding excludes pending voucher reservation', function () {
    $calc = app(LorryHireCalculationService::class);

    /** @var Collection<int, TransporterVoucher> $vouchers */
    $vouchers = new Collection([
        new TransporterVoucher(['amount' => '41736.00', 'status' => VoucherStatus::Paid]),
        new TransporterVoucher(['amount' => '1000.00', 'status' => VoucherStatus::Pending]),
    ]);

    $outstanding = $calc->outstandingAmount('50000.00', $vouchers, new Collection);
    $remaining = bcsub($outstanding, $calc->reservedVoucherAmount($vouchers), 2);

    expect($outstanding)->toBe('8264.00')
        ->and($remaining)->toBe('7264.00');
});

test('legacy scenario: new booking without advance leaves full net outstanding', function () {
    $calc = app(LorryHireCalculationService::class);

    $net = $calc->netPayable('10000.00', '2000.00', '0.00', new Collection);
    $outstanding = $calc->outstandingAmount($net, new Collection, new Collection);

    expect($net)->toBe('8000.00')
        ->and($outstanding)->toBe('8000.00');
});