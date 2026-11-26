<?php

namespace App\Domains\Partload\Challan;

/**
 * Rules §12 Challan TOTAL formula (exact):
 * TOTAL = toPay + crossingFreight - labour - kat - homeDelivery - truckFreight + cashPayment - cashReceived
 */
final class PartloadChallanTotals
{
    /**
     * @param  array{
     *     to_pay_amount?: numeric-string|float|int|string|null,
     *     crossing_freight?: numeric-string|float|int|string|null,
     *     labour_charge?: numeric-string|float|int|string|null,
     *     kat?: numeric-string|float|int|string|null,
     *     home_delivery_charge?: numeric-string|float|int|string|null,
     *     truck_freight?: numeric-string|float|int|string|null,
     *     cash_payment?: numeric-string|float|int|string|null,
     *     cash_received?: numeric-string|float|int|string|null,
     *     cartage_dalali_pf?: numeric-string|float|int|string|null,
     *     cash_paid?: numeric-string|float|int|string|null,
     *     other_charge?: numeric-string|float|int|string|null,
     * }  $amounts
     * @return numeric-string
     */
    public static function computeTotal(array $amounts): string
    {
        $total = self::money($amounts['to_pay_amount'] ?? 0)
            + self::money($amounts['crossing_freight'] ?? 0)
            - self::money($amounts['labour_charge'] ?? 0)
            - self::money($amounts['kat'] ?? 0)
            - self::money($amounts['home_delivery_charge'] ?? 0)
            - self::money($amounts['truck_freight'] ?? 0)
            + self::money($amounts['cash_payment'] ?? 0)
            - self::money($amounts['cash_received'] ?? 0);

        return number_format($total, 2, '.', '');
    }

    private static function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }
}
