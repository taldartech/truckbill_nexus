<?php

namespace App\Support\Vehicle;

use Illuminate\Validation\ValidationException;

/**
 * Normalize and validate Indian vehicle registration numbers for transport operations.
 *
 * Supported (normalized, no separators):
 * - Standard series: AA00AA0000, AA00A0000, AA00AAA0000 (state + RTO + series + number)
 * - Bharat (BH) series: 00BH0000AA
 */
final class IndianVehicleRegistration
{
    private const STANDARD = '/^[A-Z]{2}[0-9]{1,2}[A-Z]{1,3}[0-9]{4}$/';

    private const BHARAT = '/^[0-9]{2}BH[0-9]{4}[A-Z]{2}$/';

    public static function normalize(mixed $value): string
    {
        $raw = strtoupper(trim((string) $value));
        $raw = preg_replace('/[\s\-]+/', '', $raw) ?? $raw;

        return $raw;
    }

    public static function isValid(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        return preg_match(self::STANDARD, $normalized) === 1
            || preg_match(self::BHARAT, $normalized) === 1;
    }

    /**
     * @throws ValidationException
     */
    public static function assertValid(mixed $value, string $field = 'registration_number'): string
    {
        $normalized = self::normalize($value);
        if ($normalized === '') {
            throw ValidationException::withMessages([
                $field => 'Enter the vehicle registration number.',
            ]);
        }

        if (! self::isValid($normalized)) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid Indian vehicle registration number (for example MH12AB1234 or 21BH1234AA).',
            ]);
        }

        return $normalized;
    }
}
