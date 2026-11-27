<?php

namespace App\Support\Presentation;

/**
 * Operator-facing rupee amounts in Indian digit grouping (₹1,23,456.50; whole amounts omit paise).
 */
final class Money
{
    /**
     * @param  numeric-string  $amount
     */
    public static function inr(string $amount): string
    {
        $normalized = number_format((float) $amount, 2, '.', '');
        $negative = str_starts_with($normalized, '-');
        [$rupees, $paise] = explode('.', ltrim($normalized, '-'));

        $grouped = strlen($rupees) > 3
            ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($rupees, 0, -3)).','.substr($rupees, -3)
            : $rupees;

        return ($negative ? '-' : '').'₹'.$grouped.($paise === '00' ? '' : '.'.$paise);
    }
}
