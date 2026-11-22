<?php

namespace App\Domains\Subscription\Quota;

use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\CompanyQuotaCounter;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Broker/Partload quota foundations. Does not wire LR/Bilty creation gates yet.
 */
final class QuotaAllocationService
{
    /**
     * Prorate included quota units for the selected tenure (floor fractional).
     */
    public function prorateIncludedUnits(string $annualIncludedUnits, SubscriptionTenure $tenure, int $couponExtraMonths = 0): string
    {
        $base = match ($tenure) {
            SubscriptionTenure::Monthly => DecimalMath::mul($annualIncludedUnits, DecimalMath::div('1', '12', 8), 8),
            SubscriptionTenure::Quarterly => DecimalMath::mul($annualIncludedUnits, DecimalMath::div('3', '12', 8), 8),
            SubscriptionTenure::HalfYearly => DecimalMath::mul($annualIncludedUnits, DecimalMath::div('6', '12', 8), 8),
            SubscriptionTenure::Yearly,
            SubscriptionTenure::ThreeYearFullAdvance,
            SubscriptionTenure::ThreeYearAnnualInstalments => DecimalMath::of($annualIncludedUnits, 8),
        };

        if ($couponExtraMonths > 0) {
            $extra = DecimalMath::mul($annualIncludedUnits, DecimalMath::div((string) $couponExtraMonths, '12', 8), 8);
            $base = DecimalMath::add($base, $extra, 8);
        }

        // Floor fractional quotas (positive values only).
        return str_contains($base, '.') ? explode('.', $base, 2)[0] : $base;
    }

    /**
     * Three-year subscriptions refresh included quotas every twelve months.
     *
     * @return array{year_index: int, period_start: string, period_end: string}
     */
    public function threeYearQuotaWindow(CarbonInterface $termStart, CarbonInterface $asOf): array
    {
        $start = $termStart->startOfDay();
        $asOfDay = $asOf->startOfDay();
        if ($asOfDay->lessThan($start)) {
            throw new SubscriptionDomainException('asOf is before term start.');
        }

        $yearIndex = (int) floor($start->diffInDays($asOfDay) / 365);
        $yearIndex = max(0, min(2, $yearIndex));
        $periodStart = $start->addYears($yearIndex);
        $periodEnd = $periodStart->addYear()->subDay();

        return [
            'year_index' => $yearIndex,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
        ];
    }

    public function ensureCounter(
        Company $company,
        string $quotaType,
        string $includedUnits,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): CompanyQuotaCounter {
        return CompanyQuotaCounter::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'quota_type' => $quotaType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'id' => (string) Str::uuid(),
                'included_units' => $includedUnits,
                'used_units' => '0.0000',
            ],
        );
    }

    /**
     * @return array{percent_used: float, alert: bool, block: bool}
     */
    public function thresholdState(CompanyQuotaCounter $counter): array
    {
        $included = (float) $counter->included_units;
        $used = (float) $counter->used_units;
        $percent = $included <= 0.0 ? ($used > 0 ? 100.0 : 0.0) : ($used / $included) * 100.0;
        $alertAt = (float) config('subscription.quota.alert_percent', 80);
        $blockAt = (float) config('subscription.quota.block_percent', 100);

        return [
            'percent_used' => round($percent, 2),
            'alert' => $percent >= $alertAt,
            'block' => $percent >= $blockAt,
        ];
    }
}
