<?php

namespace App\Domains\Subscription\Enums;

enum SubscriptionTenure: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';
    case ThreeYearFullAdvance = 'three_year_full_advance';
    case ThreeYearAnnualInstalments = 'three_year_annual_instalments';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
            self::ThreeYearFullAdvance, self::ThreeYearAnnualInstalments => 36,
        };
    }

    /**
     * Tenure discount percent applied after converting annual → period amount (Rules §15).
     */
    public function discountPercent(): string
    {
        return match ($this) {
            self::Monthly => '0',
            self::Quarterly => '5',
            self::HalfYearly => '8',
            self::Yearly => '15',
            self::ThreeYearFullAdvance => '25',
            self::ThreeYearAnnualInstalments => '20',
        };
    }

    /**
     * Multiplier against annual catalogue price before tenure discount.
     */
    public function annualMultiplier(): string
    {
        return match ($this) {
            self::Monthly => '0.0833333333', // 1/12 — used via months/12 exact path instead
            self::Quarterly => '0.25',
            self::HalfYearly => '0.5',
            self::Yearly => '1',
            self::ThreeYearFullAdvance, self::ThreeYearAnnualInstalments => '3',
        };
    }
}
