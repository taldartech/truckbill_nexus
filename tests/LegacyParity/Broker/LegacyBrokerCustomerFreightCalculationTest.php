<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Unit/LorryReceiptCalculationServiceTest.php
 * and docs/Truckbill (Broker) - Calculations.md (customer actual_amount / amount).
 *
 * Nexus stores weights in KG; scenarios use MT-equivalent inputs via FreightCalculator.
 */

use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Freight\FreightCalculator;

test('legacy LR store fixed amount sets line freight from amount', function () {
    $calc = new FreightCalculator;

    expect($calc->compute(FreightRateType::FixedAmount, '5000'))->toBe('5000.00');
});

test('legacy LR store variable uses rate times charged weight in mt semantics', function () {
    $calc = new FreightCalculator;

    // 8 MT charged × 100 rate (per MT) = 800.00
    expect($calc->compute(
        FreightRateType::PerChargedWeight,
        '100',
        null,
        null,
        '8000',
        FreightRateSemantics::WeightMt,
    ))->toBe('800.00');
});

test('legacy LR update uses min loading and charged weight basis for variable freight', function () {
    $calc = new FreightCalculator;

    // min(12 MT actual, 10 MT charged) billable basis → 10 MT × 50 = 500.00
    expect($calc->compute(
        FreightRateType::PerChargedWeight,
        '50',
        null,
        '12000',
        '10000',
        FreightRateSemantics::WeightMt,
    ))->toBe('500.00');
});

test('legacy transporter FR variable uses min weight for tr_amount per charged weight rate', function () {
    $calc = new FreightCalculator;

    expect($calc->compute(
        FreightRateType::PerChargedWeight,
        '100',
        null,
        '12000',
        '9000',
        FreightRateSemantics::WeightMt,
    ))->toBe('900.00');
});

test('legacy LR update variable equal weights uses rate times charged basis', function () {
    $calc = new FreightCalculator;

    // 10 MT × 100 rate (per MT) = 1000.00 — LEG-BRO-00024
    expect($calc->compute(
        FreightRateType::PerChargedWeight,
        '100',
        null,
        '10000',
        '10000',
        FreightRateSemantics::WeightMt,
    ))->toBe('1000.00');
});
