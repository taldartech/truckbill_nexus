<?php

use App\Support\Weight\OperationalWeight;

test('converts MT to KG and back without drift for common operational values', function (string $mt, string $kg) {
    expect(OperationalWeight::mtToKg($mt))->toBe($kg)
        ->and(OperationalWeight::kgToMt($kg))->toBe($mt);
})->with([
    ['1.000', '1000.000'],
    ['8.000', '8000.000'],
    ['8.125', '8125.000'],
    ['0.001', '1.000'],
    ['0.000', '0.000'],
]);

test('derives actual MT from units and weight per unit KG', function () {
    expect(OperationalWeight::actualMtFromUnitsAndWeightPerUnitKg('100', '80'))
        ->toBe('8.000');
});

test('derives weight per unit KG from actual MT and units', function () {
    expect(OperationalWeight::weightPerUnitKgFromActualMt('8.000', '100'))
        ->toBe('80.000');
});

test('rejects negative weights', function () {
    OperationalWeight::mtToKg('-1');
})->throws(InvalidArgumentException::class);

test('optional helpers return null for blank input', function () {
    expect(OperationalWeight::optionalMtToKg(null))->toBeNull()
        ->and(OperationalWeight::optionalMtToKg(''))->toBeNull()
        ->and(OperationalWeight::optionalKgToMt(null))->toBeNull()
        ->and(OperationalWeight::optionalKgToMt(''))->toBeNull();
});
