<?php

namespace App\Rules;

use App\Support\Vehicle\IndianVehicleRegistration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class IndianVehicleRegistrationRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = IndianVehicleRegistration::normalize($value);
        if (! IndianVehicleRegistration::isValid($normalized)) {
            $fail('Enter a valid Indian vehicle registration number (for example MH12AB1234 or 21BH1234AA).');
        }
    }
}
