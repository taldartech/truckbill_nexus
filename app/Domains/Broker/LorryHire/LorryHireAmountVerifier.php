<?php

namespace App\Domains\Broker\LorryHire;

use InvalidArgumentException;

/**
 * Legacy FreightBookingAmountVerifier parity for submitted hire amount consistency.
 */
final class LorryHireAmountVerifier
{
    private const TOLERANCE = '0.01';

    /**
     * @param  array{
     *     is_fixed_amount?: bool,
     *     tr_fr_rate?: float|int|string,
     *     tr_amount?: float|int|string,
     *     loading_weight?: float|int|string,
     *     unloading_weight?: float|int|string,
     *     tds_amount?: float|int|string,
     *     advance_amount?: float|int|string,
     *     balance_amount?: float|int|string,
     *     negative_adjusted_amount?: float|int|string,
     *     shortage_amount?: float|int|string,
     *     damage_amount?: float|int|string,
     *     detention?: float|int|string,
     *     deviation?: float|int|string,
     *     misc_expense?: float|int|string,
     *     custom_amounts_total?: float|int|string,
     *     voucher_payments_total?: float|int|string,
     * }  $amounts
     */
    public static function verify(array $amounts): void
    {
        $isFixedAmount = (bool) ($amounts['is_fixed_amount'] ?? false);
        $trFrRate = (float) ($amounts['tr_fr_rate'] ?? 0);
        $trAmount = self::roundMoney($amounts['tr_amount'] ?? 0);
        $loadingWeight = (float) ($amounts['loading_weight'] ?? 0);
        $unloadingWeight = (float) ($amounts['unloading_weight'] ?? $loadingWeight);
        $tdsAmount = self::roundMoney($amounts['tds_amount'] ?? 0);
        $advanceAmount = self::roundMoney($amounts['advance_amount'] ?? 0);
        $balanceAmount = self::roundMoney($amounts['balance_amount'] ?? 0);
        $negativeAdjustedAmount = self::roundMoney($amounts['negative_adjusted_amount'] ?? 0);
        $shortageAmount = self::roundMoney($amounts['shortage_amount'] ?? 0);
        $damageAmount = self::roundMoney($amounts['damage_amount'] ?? 0);
        $detention = self::roundMoney($amounts['detention'] ?? 0);
        $deviation = self::roundMoney($amounts['deviation'] ?? 0);
        $miscExpense = self::roundMoney($amounts['misc_expense'] ?? 0);
        $customAmountsTotal = self::roundMoney($amounts['custom_amounts_total'] ?? 0);
        $voucherPaymentsTotal = self::roundMoney($amounts['voucher_payments_total'] ?? 0);

        if (! self::amountsMatch($advanceAmount, $voucherPaymentsTotal)) {
            throw new InvalidArgumentException(sprintf(
                'Advance amount (%.2f) does not match voucher payments total (%.2f).',
                $advanceAmount,
                $voucherPaymentsTotal,
            ));
        }

        if (! $isFixedAmount) {
            $billableWeight = min($loadingWeight, $unloadingWeight);
            $expectedTrAmount = round($trFrRate * $billableWeight, 2);

            if (! self::amountsMatch($trAmount, $expectedTrAmount)) {
                throw new InvalidArgumentException(sprintf(
                    'Transporter amount (%.2f) does not match rate x billable weight (%.2f).',
                    $trAmount,
                    $expectedTrAmount,
                ));
            }
        }

        $basePayable = round(
            $trAmount
            - $tdsAmount
            - $damageAmount
            - $shortageAmount
            + $detention
            + $deviation
            - $miscExpense
            + $customAmountsTotal,
            2,
        );

        $expectedNegativeAdjusted = max(0.0, round($advanceAmount - $basePayable, 2));
        $expectedBalance = round($basePayable + $expectedNegativeAdjusted - $advanceAmount, 2);

        if (! self::amountsMatch($negativeAdjustedAmount, $expectedNegativeAdjusted)) {
            throw new InvalidArgumentException(sprintf(
                'Negative adjusted amount (%.2f) does not match expected value (%.2f).',
                $negativeAdjustedAmount,
                $expectedNegativeAdjusted,
            ));
        }

        if (! self::amountsMatch($balanceAmount, $expectedBalance)) {
            throw new InvalidArgumentException(sprintf(
                'Balance amount (%.2f) does not match expected value (%.2f).',
                $balanceAmount,
                $expectedBalance,
            ));
        }
    }

    private static function roundMoney(float|int|string $amount): float
    {
        return round((float) $amount, 2);
    }

    private static function amountsMatch(float $left, float $right): bool
    {
        return abs($left - $right) <= (float) self::TOLERANCE;
    }
}
