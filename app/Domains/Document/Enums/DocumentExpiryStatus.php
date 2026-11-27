<?php

namespace App\Domains\Document\Enums;

use Carbon\CarbonInterface;

enum DocumentExpiryStatus: string
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';

    public const EXPIRING_SOON_DAYS = 30;

    public static function forExpiry(?CarbonInterface $expiresOn, CarbonInterface $today): ?self
    {
        if ($expiresOn === null) {
            return null;
        }

        $expiry = $expiresOn->copy()->startOfDay();
        $day = $today->copy()->startOfDay();

        if ($expiry->lessThan($day)) {
            return self::Expired;
        }

        if ($expiry->lessThanOrEqualTo($day->copy()->addDays(self::EXPIRING_SOON_DAYS))) {
            return self::ExpiringSoon;
        }

        return self::Valid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::ExpiringSoon => 'Expiring soon',
            self::Expired => 'Expired',
        };
    }
}
