<?php

/**
 * Legacy reference: legacy/truckbill_broker/tests/Unit/Support/MoneyTest.php
 */

use App\Domains\Subscription\Payments\Support\RazorpayMoney;

test('LEG-BRO-00012 legacy format rupees with paise decimals', function () {
    expect(number_format(25164.24, 2, '.', ''))->toBe('25164.24')
        ->and(number_format(20000, 2, '.', ''))->toBe('20000.00');
});

test('LEG-BRO-00013 legacy to paise for razorpay only', function () {
    expect(RazorpayMoney::toPaise('25164.24'))->toBe(2516424)
        ->and(RazorpayMoney::toPaise('20000.00'))->toBe(2000000);
});
