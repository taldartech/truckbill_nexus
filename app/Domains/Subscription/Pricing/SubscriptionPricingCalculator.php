<?php

namespace App\Domains\Subscription\Pricing;

use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Support\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Confirmed Rules §15 pricing: discount order, caps, proration, mid-term module discounts.
 * Does not activate subscriptions or invent AUD-CON-006 entitlements.
 */
final class SubscriptionPricingCalculator
{
    public const MONETARY_DISCOUNT_CAP_PERCENT = '30';

    public const DISPLAY_LIST_DIVISOR = '0.75';

    public const RENEWAL_DISCOUNT_PERCENT = '5';

    /**
     * Display list price = base / 0.75 (Rules §15).
     */
    public function displayListPrice(string $catalogueAnnualPrice): string
    {
        return DecimalMath::div($catalogueAnnualPrice, self::DISPLAY_LIST_DIVISOR, 2);
    }

    /**
     * Bundle discount percent for N core modules: 1→0%, 2→10%, 3+→15%.
     */
    public function bundleDiscountPercent(int $coreModuleCount): string
    {
        if ($coreModuleCount < 1) {
            throw new InvalidArgumentException('At least one core module is required for pricing.');
        }

        return match (true) {
            $coreModuleCount === 1 => '0',
            $coreModuleCount === 2 => '10',
            default => '15',
        };
    }

    /**
     * Mid-term module addition discount on the new prorated item only.
     * First module 0%; second 10%; third+ 15%.
     */
    public function midTermModuleDiscountPercent(int $existingCoreModuleCount): string
    {
        return match (true) {
            $existingCoreModuleCount <= 0 => '0',
            $existingCoreModuleCount === 1 => '10',
            default => '15',
        };
    }

    /**
     * Period amount before tenure discount = annual × subscribed_months / 12.
     * Three-year tenures use annual × 3 before discount (Rules §15).
     */
    public function periodAmountBeforeTenureDiscount(string $discountedAnnualAfterBundle, SubscriptionTenure $tenure): string
    {
        if ($tenure === SubscriptionTenure::ThreeYearFullAdvance
            || $tenure === SubscriptionTenure::ThreeYearAnnualInstalments) {
            return DecimalMath::mul($discountedAnnualAfterBundle, '3', 2);
        }

        return DecimalMath::div(
            DecimalMath::mul($discountedAnnualAfterBundle, (string) $tenure->months(), 8),
            '12',
            2,
        );
    }

    /**
     * @param  list<string>  $coreModuleAnnualPrices  Catalogue annual prices for selected core modules
     * @return array{
     *     base_annual: string,
     *     after_bundle: string,
     *     bundle_percent: string,
     *     period_before_tenure: string,
     *     after_tenure: string,
     *     tenure_percent: string,
     *     after_renewal: string,
     *     renewal_percent: string,
     *     coupon_monetary_applied: string,
     *     coupon_extra_months: int,
     *     final_period_amount: string,
     *     monetary_cap: string
     * }
     */
    public function calculate(
        array $coreModuleAnnualPrices,
        SubscriptionTenure $tenure,
        bool $renewalEligible = false,
        ?string $couponPercent = null,
        ?string $couponFixed = null,
        int $couponExtraMonths = 0,
    ): array {
        if ($coreModuleAnnualPrices === []) {
            throw new InvalidArgumentException('Core module prices are required.');
        }

        $baseAnnual = '0.00';
        foreach ($coreModuleAnnualPrices as $price) {
            $baseAnnual = DecimalMath::add($baseAnnual, $price, 2);
        }

        $bundlePercent = $this->bundleDiscountPercent(count($coreModuleAnnualPrices));
        $afterBundle = DecimalMath::applyPercentDiscount($baseAnnual, $bundlePercent, 2);

        $periodBeforeTenure = $this->periodAmountBeforeTenureDiscount($afterBundle, $tenure);
        $tenurePercent = $tenure->discountPercent();
        $afterTenure = DecimalMath::applyPercentDiscount($periodBeforeTenure, $tenurePercent, 2);

        $renewalPercent = $renewalEligible ? self::RENEWAL_DISCOUNT_PERCENT : '0';
        $afterRenewal = $renewalEligible
            ? DecimalMath::applyPercentDiscount($afterTenure, $renewalPercent, 2)
            : $afterTenure;

        $monetaryCap = DecimalMath::percentOf($periodBeforeTenure, self::MONETARY_DISCOUNT_CAP_PERCENT, 2);
        $discountsBeforeCoupon = DecimalMath::sub($periodBeforeTenure, $afterRenewal, 2);
        $remainingCap = DecimalMath::sub($monetaryCap, $discountsBeforeCoupon, 2);
        if (DecimalMath::compare($remainingCap, '0', 2) < 0) {
            $remainingCap = '0.00';
        }

        $couponMonetary = '0.00';
        $working = $afterRenewal;

        if ($couponPercent !== null && $couponPercent !== '') {
            $requested = DecimalMath::percentOf($working, $couponPercent, 2);
            $couponMonetary = DecimalMath::min($requested, $remainingCap, 2);
            $working = DecimalMath::sub($working, $couponMonetary, 2);
            $remainingCap = DecimalMath::sub($remainingCap, $couponMonetary, 2);
        }

        if ($couponFixed !== null && $couponFixed !== '') {
            $requested = DecimalMath::of($couponFixed, 2);
            $applied = DecimalMath::min($requested, $remainingCap, 2);
            $couponMonetary = DecimalMath::add($couponMonetary, $applied, 2);
            $working = DecimalMath::sub($working, $applied, 2);
        }

        // Extra-month coupons are excluded from the monetary cap (Rules §15).
        if ($couponExtraMonths < 0) {
            throw new InvalidArgumentException('Coupon extra months cannot be negative.');
        }

        return [
            'base_annual' => $baseAnnual,
            'after_bundle' => $afterBundle,
            'bundle_percent' => $bundlePercent,
            'period_before_tenure' => $periodBeforeTenure,
            'after_tenure' => $afterTenure,
            'tenure_percent' => $tenurePercent,
            'after_renewal' => $afterRenewal,
            'renewal_percent' => $renewalPercent,
            'coupon_monetary_applied' => $couponMonetary,
            'coupon_extra_months' => $couponExtraMonths,
            'final_period_amount' => DecimalMath::of($working, 2),
            'monetary_cap' => $monetaryCap,
        ];
    }

    /**
     * Actual-day proration: annual × remaining_days / days_in_annual_term.
     * Includes activation day; leap-aware; round 2 decimals (Rules §15).
     */
    public function prorateAnnual(
        string $annualAmount,
        CarbonImmutable $activationDay,
        CarbonImmutable $termEndExclusiveOrRenewalDate,
    ): string {
        $activation = $activationDay->startOfDay();
        $renewal = $termEndExclusiveOrRenewalDate->startOfDay();

        if ($renewal->lessThanOrEqualTo($activation)) {
            throw new InvalidArgumentException('Renewal/common date must be after activation day.');
        }

        $remainingDays = $activation->diffInDays($renewal) + 1; // include activation day
        $termStart = $renewal->subYear();
        $daysInAnnualTerm = $termStart->diffInDays($renewal);

        if ($daysInAnnualTerm < 1) {
            throw new InvalidArgumentException('Invalid annual term length.');
        }

        $raw = DecimalMath::div(
            DecimalMath::mul($annualAmount, (string) $remainingDays, 8),
            (string) $daysInAnnualTerm,
            8,
        );

        return DecimalMath::round($raw, 2);
    }
}
