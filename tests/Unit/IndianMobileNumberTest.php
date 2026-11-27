<?php

use App\Rules\IndianMobileNumber;

function indianMobileFailure(mixed $value): ?string
{
    $failure = null;
    (new IndianMobileNumber)->validate('mobile', $value, function (string $message) use (&$failure): void {
        $failure = $message;
    });

    return $failure;
}

test('accepts ten digit indian mobile numbers', function (string $mobile) {
    expect(indianMobileFailure($mobile))->toBeNull();
})->with(['9876543210', '6000000000', '7999999999']);

test('rejects values that are not ten digit indian mobile numbers', function (mixed $mobile) {
    expect(indianMobileFailure($mobile))->toBe(IndianMobileNumber::MESSAGE);
})->with(['987654321', '98765432101', '5876543210', '98765-43210', '+919876543210', 'abcdefghij', [['9876543210']]]);

test('normalizes external separators and the india country prefix only', function (string $raw, string $expected) {
    expect(IndianMobileNumber::normalizeExternal($raw))->toBe($expected);
})->with([
    ['+91 98765 43210', '9876543210'],
    ['98765-43210', '9876543210'],
    ['919876543210', '9876543210'],
    ['09876543210', '09876543210'],
    ['12345', '12345'],
]);
