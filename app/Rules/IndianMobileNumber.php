<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Authoritative Indian mobile number rule (Rules §29): exactly ten digits, starting 6–9.
 *
 * Mobile numbers are stored as strings; this rule never truncates or rewrites stored values.
 */
final class IndianMobileNumber implements ValidationRule
{
    public const PATTERN = '/^[6-9][0-9]{9}$/';

    public const MESSAGE = 'Enter a valid 10-digit mobile number.';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Strip separators and an optional +91 / 91 country prefix from externally submitted numbers.
     */
    public static function normalizeExternal(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        return $digits;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail(self::MESSAGE);

            return;
        }

        if (! self::isValid(trim((string) $value))) {
            $fail(self::MESSAGE);
        }
    }
}
