<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Unit/Helpers/NumberHelpersTest.php
 */

use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Support\Presentation\IndianNumber;

test('LEG-BRO-00027 legacy round up tds amount rounds up to integer', function () {
    $calc = app(LorryHireCalculationService::class);

    expect($calc->roundUpTdsAmount('100.001'))->toBe('101')
        ->and($calc->roundUpTdsAmount('100.00'))->toBe('100')
        ->and($calc->roundUpTdsAmount('99.991'))->toBe('100')
        ->and($calc->roundUpTdsAmount('2000.00'))->toBe('2000')
        ->and($calc->roundUpTdsAmount('2000.001'))->toBe('2001');
});

test('LEG-BRO-00028 legacy get indian currency whole and paise', function () {
    expect(IndianNumber::amountInWords(1.00))->toBe('One  Rupees ')
        ->and(IndianNumber::amountInWords(1.25))->toBe('One  Rupees And Twenty Five Paise')
        ->and(IndianNumber::amountInWords(100010))->toBe('One Lakh Ten  Rupees ');
});

test('LEG-BRO-00029 legacy format indian number uses lakh crore grouping', function () {
    expect(IndianNumber::format(1050000))->toBe('10,50,000.00')
        ->and(IndianNumber::format(100000))->toBe('1,00,000.00')
        ->and(IndianNumber::format(999))->toBe('999.00')
        ->and(IndianNumber::format(1000))->toBe('1,000.00')
        ->and(IndianNumber::format(12345678.9))->toBe('1,23,45,678.90')
        ->and(IndianNumber::format(-1050000))->toBe('-10,50,000.00')
        ->and(IndianNumber::format(1050, 0))->toBe('1,050');
});
