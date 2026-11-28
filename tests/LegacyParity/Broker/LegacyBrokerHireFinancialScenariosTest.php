<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Unit/Services/FreightBookingAmountVerifierTest.php
 * and docs/Truckbill (Broker) - Calculations.md (transporter balance semantics).
 *
 * These scenarios preserve legacy business inputs/outcomes against authoritative Nexus services.
 */

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\LorryHire\LorryHireAmountVerifier;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Models\BrokerAdjustment;
use App\Models\TransporterVoucher;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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

test('LEG-BRO-00068 legacy verify passes for consistent amounts', function () {
    $amounts = [
        'is_fixed_amount' => false,
        'tr_fr_rate' => 1075,
        'tr_amount' => 41054.25,
        'loading_weight' => 41.34,
        'unloading_weight' => 38.19,
        'tds_amount' => 445,
        'advance_amount' => 41736,
        'balance_amount' => 0,
        'negative_adjusted_amount' => 23176.75,
        'shortage_amount' => 22050,
        'damage_amount' => 0,
        'detention' => 0,
        'deviation' => 0,
        'misc_expense' => 0,
        'custom_amounts_total' => 0,
        'voucher_payments_total' => 41736,
    ];

    LorryHireAmountVerifier::verify($amounts);

    expect(true)->toBeTrue();
});

test('LEG-BRO-00070 legacy verify throws for inconsistent amounts', function (array $amounts, string $expectedMessagePart) {
    expect(fn () => LorryHireAmountVerifier::verify($amounts))
        ->toThrow(InvalidArgumentException::class, $expectedMessagePart);
})->with([
    'advance mismatch' => [
        [
            'is_fixed_amount' => false,
            'tr_fr_rate' => 1000,
            'tr_amount' => 10000,
            'loading_weight' => 10,
            'unloading_weight' => 10,
            'tds_amount' => 100,
            'advance_amount' => 10000,
            'balance_amount' => 0,
            'negative_adjusted_amount' => 600,
            'shortage_amount' => 0,
            'damage_amount' => 0,
            'detention' => 0,
            'deviation' => 0,
            'misc_expense' => 0,
            'custom_amounts_total' => 0,
            'voucher_payments_total' => 10500,
        ],
        'does not match voucher payments total',
    ],
    'transporter amount mismatch' => [
        [
            'is_fixed_amount' => false,
            'tr_fr_rate' => 1000,
            'tr_amount' => 9000,
            'loading_weight' => 10,
            'unloading_weight' => 10,
            'tds_amount' => 100,
            'advance_amount' => 10500,
            'balance_amount' => 0,
            'negative_adjusted_amount' => 600,
            'shortage_amount' => 0,
            'damage_amount' => 0,
            'detention' => 0,
            'deviation' => 0,
            'misc_expense' => 0,
            'custom_amounts_total' => 0,
            'voucher_payments_total' => 10500,
        ],
        'does not match rate x billable weight',
    ],
    'balance mismatch' => [
        [
            'is_fixed_amount' => false,
            'tr_fr_rate' => 1000,
            'tr_amount' => 10000,
            'loading_weight' => 10,
            'unloading_weight' => 10,
            'tds_amount' => 100,
            'advance_amount' => 10500,
            'balance_amount' => -100,
            'negative_adjusted_amount' => 600,
            'shortage_amount' => 0,
            'damage_amount' => 0,
            'detention' => 0,
            'deviation' => 0,
            'misc_expense' => 0,
            'custom_amounts_total' => 0,
            'voucher_payments_total' => 10500,
        ],
        'Balance amount',
    ],
    'negative adjusted mismatch' => [
        [
            'is_fixed_amount' => false,
            'tr_fr_rate' => 1000,
            'tr_amount' => 10000,
            'loading_weight' => 10,
            'unloading_weight' => 10,
            'tds_amount' => 100,
            'advance_amount' => 10500,
            'balance_amount' => 0,
            'negative_adjusted_amount' => 100,
            'shortage_amount' => 0,
            'damage_amount' => 0,
            'detention' => 0,
            'deviation' => 0,
            'misc_expense' => 0,
            'custom_amounts_total' => 0,
            'voucher_payments_total' => 10500,
        ],
        'Negative adjusted amount',
    ],
]);

test('LEG-BRO-00069 legacy verify passes for new booking without advance', function () {
    LorryHireAmountVerifier::verify([
        'is_fixed_amount' => false,
        'tr_fr_rate' => 1000,
        'tr_amount' => 10000,
        'loading_weight' => 10,
        'unloading_weight' => 10,
        'tds_amount' => 2000,
        'advance_amount' => 0,
        'balance_amount' => 8000,
        'negative_adjusted_amount' => 0,
        'shortage_amount' => 0,
        'damage_amount' => 0,
        'detention' => 0,
        'deviation' => 0,
        'misc_expense' => 0,
        'custom_amounts_total' => 0,
        'voucher_payments_total' => 0,
    ]);

    expect(true)->toBeTrue();
});

test('legacy scenario: new booking without advance leaves full net outstanding', function () {
    $calc = app(LorryHireCalculationService::class);

    $net = $calc->netPayable('10000.00', '2000.00', '0.00', new Collection);
    $outstanding = $calc->outstandingAmount($net, new Collection, new Collection);

    expect($net)->toBe('8000.00')
        ->and($outstanding)->toBe('8000.00');
});
