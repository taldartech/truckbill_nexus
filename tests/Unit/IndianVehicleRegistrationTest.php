<?php

use App\Support\Vehicle\IndianVehicleRegistration;

test('normalizes registration numbers to uppercase without separators', function () {
    expect(IndianVehicleRegistration::normalize(' mh12 ab 1234 '))->toBe('MH12AB1234')
        ->and(IndianVehicleRegistration::normalize('21-bh-1234-aa'))->toBe('21BH1234AA');
});

test('accepts standard and bharat series formats', function () {
    expect(IndianVehicleRegistration::isValid('MH12AB1234'))->toBeTrue()
        ->and(IndianVehicleRegistration::isValid('DL01CAB1234'))->toBeTrue()
        ->and(IndianVehicleRegistration::isValid('21BH1234AA'))->toBeTrue();
});

test('rejects invalid registration strings', function () {
    expect(IndianVehicleRegistration::isValid('NOT-A-TRUCK'))->toBeFalse()
        ->and(IndianVehicleRegistration::isValid('12345'))->toBeFalse()
        ->and(IndianVehicleRegistration::isValid(''))->toBeFalse();
});
