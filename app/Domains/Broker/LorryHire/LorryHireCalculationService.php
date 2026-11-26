<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Freight\FreightCalculator;
use App\Models\BrokerAdjustment;
use App\Models\LorryHire;
use App\Models\TransporterBookBalanceEntry;
use App\Models\TransporterVoucher;
use Illuminate\Support\Collection;

/**
 * Decimal-safe Lorry Hire payable / outstanding formulas (Rules §11).
 */
final class LorryHireCalculationService
{
    public function __construct(
        private readonly FreightCalculator $freightCalculator = new FreightCalculator,
    ) {}

    /**
     * Server-authoritative goods-line freight (client totals ignored).
     *
     * @param  array{
     *     rate_type: FreightRateType|string,
     *     rate: string|float|int,
     *     package_count?: string|float|int|null,
     *     actual_weight?: string|float|int|null,
     *     charged_weight?: string|float|int|null,
     *     is_fixed_amount?: bool,
     *     rate_semantics?: FreightRateSemantics|string|null
     * }  $line
     * @return numeric-string
     */
    public function goodsLineFreight(array $line): string
    {
        $rateType = $line['rate_type'] instanceof FreightRateType
            ? $line['rate_type']
            : FreightRateType::from((string) $line['rate_type']);

        $semantics = $this->resolveSemantics($line['rate_semantics'] ?? null);

        if (($line['is_fixed_amount'] ?? false) === true || $rateType === FreightRateType::FixedAmount) {
            return $this->money($this->freightCalculator->compute(
                FreightRateType::FixedAmount,
                $this->money($line['rate']),
                semantics: $semantics,
            ));
        }

        $packageCount = $line['package_count'] ?? null;
        if ($packageCount !== null && $packageCount !== '') {
            $packageCount = $this->nullableDecimal($packageCount, 0);
        }

        return $this->money($this->freightCalculator->compute(
            $rateType,
            $this->money($line['rate'], 4),
            packageCount: is_string($packageCount) ? $packageCount : null,
            actualWeight: $this->nullableDecimal($line['actual_weight'] ?? null, 3),
            chargedWeight: $this->nullableDecimal($line['charged_weight'] ?? null, 3),
            semantics: $semantics,
        ));
    }

    private function resolveSemantics(FreightRateSemantics|string|null $value): FreightRateSemantics
    {
        if ($value instanceof FreightRateSemantics) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return FreightRateSemantics::tryFrom($value) ?? FreightRateSemantics::forNewRows();
        }

        return FreightRateSemantics::forNewRows();
    }

    /**
     * Recompute variable-rate line freight after hire-level unloading weight (TDS basis unchanged).
     *
     * @return numeric-string
     */
    public function recalculateFreightForUnloading(LorryHire $hire, string $unloadingWeight): string
    {
        $hire->loadMissing(['goodsLines.lrGoodsRow']);

        $unloading = $this->decimal($unloadingWeight, 3);
        $freightTotal = '0.00';

        foreach ($hire->goodsLines as $line) {
            if ($line->is_fixed_amount || $line->rate_type === FreightRateType::FixedAmount) {
                $freightTotal = bcadd($freightTotal, $this->money($line->freight_amount), 2);

                continue;
            }

            $loading = $this->decimal($line->loading_weight, 3);
            $applicable = bccomp($loading, $unloading, 3) <= 0 ? $loading : $unloading;

            $freight = $this->goodsLineFreight([
                'rate_type' => $line->rate_type,
                'rate' => $line->rate,
                'is_fixed_amount' => false,
                'actual_weight' => $applicable,
                'charged_weight' => $applicable,
                'package_count' => $line->lrGoodsRow?->package_count,
                'rate_semantics' => $line->rate_semantics
                    ?? ($line->lrGoodsRow !== null ? $line->lrGoodsRow->rate_semantics : null)
                    ?? FreightRateSemantics::WeightKg,
            ]);

            $line->forceFill(['freight_amount' => $freight])->save();
            $freightTotal = bcadd($freightTotal, $freight, 2);
        }

        return $this->money($freightTotal);
    }

    /**
     * Latest unloading new weight for a hire, if recorded.
     *
     * @return numeric-string|null
     */
    public function latestUnloadingWeight(LorryHire $hire): ?string
    {
        $latest = BrokerAdjustment::query()
            ->where('company_id', $hire->company_id)
            ->where('lorry_hire_id', $hire->id)
            ->where('type', BrokerAdjustmentType::UnloadingWeight->value)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null || $latest->new_weight === null) {
            return null;
        }

        return $this->decimal($latest->new_weight, 3);
    }

    /**
     * @param  numeric-string  $freightTotal
     * @param  numeric-string  $tdsPercent
     * @return numeric-string
     */
    public function tdsAmountFromFreight(string $freightTotal, string $tdsPercent): string
    {
        $basis = $this->money($freightTotal);
        $rate = bcdiv($this->money($tdsPercent, 3), '100', 6);

        return $this->money(bcmul($basis, $rate, 6));
    }

    /**
     * Net Payable = freight + positive adjustments − negative adjustments − TDS.
     * May be negative when book-balance semantics require it (Rules §11).
     *
     * @param  numeric-string  $freightTotal
     * @param  numeric-string  $tdsAmount
     * @param  Collection<int, BrokerAdjustment>  $adjustments
     * @return numeric-string
     */
    public function netPayable(string $freightTotal, string $tdsAmount, Collection $adjustments): string
    {
        $positive = '0.00';
        $negative = '0.00';

        foreach ($adjustments as $adjustment) {
            if ($adjustment->type === BrokerAdjustmentType::UnloadingWeight) {
                continue;
            }

            $amount = $this->money($adjustment->amount);
            if ($this->isCreditAdjustment($adjustment->type)) {
                $positive = bcadd($positive, $amount, 2);
            } else {
                $negative = bcadd($negative, $amount, 2);
            }
        }

        $subtotal = bcadd($this->money($freightTotal), $positive, 2);

        return bcsub(bcsub($subtotal, $negative, 2), $this->money($tdsAmount), 2);
    }

    /**
     * Outstanding = Net Payable − settled voucher amounts + applicable book-balance entries.
     *
     * @param  numeric-string  $netPayable
     * @param  Collection<int, TransporterVoucher>  $vouchers
     * @param  Collection<int, TransporterBookBalanceEntry>  $bookEntries
     * @return numeric-string
     */
    public function outstandingAmount(
        string $netPayable,
        Collection $vouchers,
        Collection $bookEntries,
    ): string {
        $paid = '0.00';
        foreach ($vouchers as $voucher) {
            if ($voucher->status === VoucherStatus::Paid) {
                $paid = bcadd($paid, $this->money($voucher->amount), 2);
            }
        }

        $bookDelta = '0.00';
        foreach ($bookEntries as $entry) {
            $bookDelta = bcadd($bookDelta, $this->money($entry->amount), 2);
        }

        return bcadd(bcsub($this->money($netPayable), $paid, 2), $bookDelta, 2);
    }

    /**
     * @return array{
     *     freight_total: numeric-string,
     *     tds_amount: numeric-string,
     *     net_payable: numeric-string,
     *     outstanding_amount: numeric-string,
     *     balance_due: numeric-string,
     *     negative_adjusted_amount: numeric-string,
     *     reserved_voucher_amount: numeric-string,
     *     remaining_payable: numeric-string
     * }
     */
    public function summarize(LorryHire $hire): array
    {
        $hire->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);

        $freight = $this->money($hire->freight_total);
        $tds = $this->money($hire->tds_amount);
        $net = $this->netPayable($freight, $tds, $hire->adjustments);
        $outstanding = $this->outstandingAmount($net, $hire->vouchers, $hire->bookBalanceEntries);
        $reserved = $this->reservedVoucherAmount($hire->vouchers);
        $remainingPayable = bcsub($outstanding, $reserved, 2);
        if (bccomp($remainingPayable, '0.00', 2) < 0) {
            $remainingPayable = '0.00';
        }

        $balanceDue = bccomp($outstanding, '0.00', 2) >= 0 ? $outstanding : '0.00';
        $negativeAdjusted = bccomp($outstanding, '0.00', 2) < 0
            ? bcmul($outstanding, '-1', 2)
            : '0.00';

        return [
            'freight_total' => $freight,
            'tds_amount' => $tds,
            'net_payable' => $net,
            'outstanding_amount' => $outstanding,
            'balance_due' => $balanceDue,
            'negative_adjusted_amount' => $negativeAdjusted,
            'reserved_voucher_amount' => $reserved,
            'remaining_payable' => $remainingPayable,
        ];
    }

    /**
     * Sum of Pending / In Progress voucher amounts that reserve hire payable.
     *
     * @param  Collection<int, TransporterVoucher>  $vouchers
     * @return numeric-string
     */
    public function reservedVoucherAmount(Collection $vouchers): string
    {
        $reserved = '0.00';

        foreach ($vouchers as $voucher) {
            if (in_array($voucher->status, [VoucherStatus::Pending, VoucherStatus::InProgress], true)) {
                $reserved = bcadd($reserved, $this->money($voucher->amount), 2);
            }
        }

        return $reserved;
    }

    /**
     * Effective loading baseline before a new unloading adjustment.
     *
     * @return numeric-string
     */
    public function effectiveLoadingWeightBeforeUnloading(LorryHire $hire): string
    {
        $latest = $this->latestUnloadingWeight($hire);
        if ($latest !== null) {
            return $latest;
        }

        $hire->loadMissing('goodsLines');

        $loading = null;
        foreach ($hire->goodsLines as $line) {
            if ($line->is_fixed_amount || $line->rate_type === FreightRateType::FixedAmount) {
                continue;
            }

            $weight = $this->decimal($line->loading_weight, 3);
            if ($loading === null || bccomp($weight, $loading, 3) > 0) {
                $loading = $weight;
            }
        }

        return $loading ?? '0.000';
    }

    private function isCreditAdjustment(BrokerAdjustmentType|string $type): bool
    {
        $value = $type instanceof BrokerAdjustmentType ? $type->value : (string) $type;

        return in_array($value, [
            BrokerAdjustmentType::Detention->value,
            BrokerAdjustmentType::Deviation->value,
        ], true);
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return number_format(0, $scale, '.', '');
        }

        return number_format((float) $value, $scale, '.', '');
    }

    /**
     * @return numeric-string
     */
    private function decimal(mixed $value, int $scale): string
    {
        return $this->money($value, $scale);
    }

    /**
     * @return ($value is null|'' ? null : numeric-string)
     */
    private function nullableDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->money($value, $scale);
    }
}
