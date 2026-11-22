<?php

namespace App\Domains\Subscription\Payments\Support;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;

/**
 * INR decimal rupees ↔ integer paise conversion without floating point.
 */
final class RazorpayMoney
{
    public static function toPaise(string $amountInr): int
    {
        $normalized = DecimalMath::of($amountInr, 2);
        if (DecimalMath::compare($normalized, '0', 2) <= 0) {
            throw new SubscriptionDomainException('Payment amount must be positive.');
        }

        $parts = explode('.', $normalized, 2);
        $rupees = (int) $parts[0];
        $paisePart = isset($parts[1]) ? str_pad(substr($parts[1], 0, 2), 2, '0') : '00';

        return ($rupees * 100) + (int) $paisePart;
    }

    public static function fromPaise(int $paise): string
    {
        if ($paise < 0) {
            throw new SubscriptionDomainException('Paise amount cannot be negative.');
        }

        $rupees = intdiv($paise, 100);
        $frac = $paise % 100;

        return sprintf('%d.%02d', $rupees, $frac);
    }
}
