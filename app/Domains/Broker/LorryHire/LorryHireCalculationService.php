<?php

namespace App\Domains\Broker\LorryHire;

use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Enums\VoucherStatus;
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
     *     is_fixed_amount?: bool
     * }  $line
     * @return numeric-string
     */
    public function goodsLineFreight(array $line): string
    {
        $rateType = $line['rate_type'] instanceof FreightRateType
            ? $line['rate_type']
            : FreightRateType::from((string) $line['rate_type']);

        if (($line['is_fixed_amount'] ?? false) === true || $rateType === FreightRateType::FixedAmount) {
            return $this->money($this->freightCalculator->compute(
                FreightRateType::FixedAmount,
                $this->money($line['rate']),
            ));
        }

        return $this->money($this->freightCalculator->compute(
            $rateType,
            $this->money($line['rate'], 4),
            packageCount: $this->nullableDecimal($line['package_count'] ?? null, 3),
            actualWeight: $this->nullableDecimal($line['actual_weight'] ?? null, 3),
            chargedWeight: $this->nullableDecimal($line['charged_weight'] ?? null, 3),
        ));
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
     *     outstanding_amount: numeric-string
     * }
     */
    public function summarize(LorryHire $hire): array
    {
        $hire->loadMissing(['adjustments', 'vouchers', 'bookBalanceEntries']);

        $freight = $this->money($hire->freight_total);
        $tds = $this->money($hire->tds_amount);
        $net = $this->netPayable($freight, $tds, $hire->adjustments);
        $outstanding = $this->outstandingAmount($net, $hire->vouchers, $hire->bookBalanceEntries);

        return [
            'freight_total' => $freight,
            'tds_amount' => $tds,
            'net_payable' => $net,
            'outstanding_amount' => $outstanding,
        ];
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
