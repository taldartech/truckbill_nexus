<?php

namespace App\Support\Presentation;

/**
 * Shows only the last four characters of a bank account number.
 */
final class AccountNumberMask
{
    public static function mask(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $length = strlen($number);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($number, -4);
    }
}
