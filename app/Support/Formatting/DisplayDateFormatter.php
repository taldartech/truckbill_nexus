<?php

namespace App\Support\Formatting;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Server-side user-facing date formatting (PO: DD-MM-YYYY).
 */
final class DisplayDateFormatter
{
    public static function date(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_string($value)) {
            $dateOnly = substr($value, 0, 10);
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateOnly, $matches) === 1) {
                return $matches[3].'-'.$matches[2].'-'.$matches[1];
            }
            $value = Carbon::parse($value);
        }

        return $value->format('d-m-Y');
    }

    public static function dateTime(
        DateTimeInterface|string|null $value,
        string $timezone = 'Asia/Kolkata',
    ): string {
        if ($value === null || $value === '') {
            return '—';
        }

        $carbon = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::parse($value);

        return $carbon->timezone($timezone)->format('d-m-Y h:i A');
    }
}
