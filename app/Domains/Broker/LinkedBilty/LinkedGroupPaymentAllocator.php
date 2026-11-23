<?php

namespace App\Domains\Broker\LinkedBilty;

use App\Domains\Broker\Exceptions\BrokerDomainException;

/**
 * Proportional Linked Bilty group payment allocation (Rules §11).
 *
 * Deterministic residual rounding: largest-remainder method on scale-2 money.
 */
final class LinkedGroupPaymentAllocator
{
    /**
     * @param  array<string, numeric-string>  $eligibleOutstandingByHireId  keyed by lorry_hire_id
     * @param  numeric-string|float|int  $groupPaymentAmount
     * @return array<string, numeric-string> allocation by lorry_hire_id (only positive shares)
     */
    public function allocate(array $eligibleOutstandingByHireId, string|float|int $groupPaymentAmount): array
    {
        $payment = $this->money($groupPaymentAmount);
        if (bccomp($payment, '0', 2) !== 1) {
            throw new BrokerDomainException('Group payment amount must be greater than zero.');
        }

        $totalEligible = '0.00';
        foreach ($eligibleOutstandingByHireId as $outstanding) {
            $value = $this->money($outstanding);
            if (bccomp($value, '0', 2) === -1) {
                throw new BrokerDomainException('Eligible outstanding cannot be negative for proportional allocation.');
            }
            $totalEligible = bcadd($totalEligible, $value, 2);
        }

        if (bccomp($totalEligible, '0', 2) !== 1) {
            throw new BrokerDomainException('No eligible outstanding balance for group payment.');
        }

        if (bccomp($payment, $totalEligible, 2) === 1) {
            throw new BrokerDomainException('Group payment cannot exceed total eligible outstanding.');
        }

        /** @var array<string, array{floor: numeric-string, remainder: numeric-string}> $parts */
        $parts = [];
        $allocated = '0.00';

        foreach ($eligibleOutstandingByHireId as $hireId => $outstanding) {
            $shareExact = bcmul($payment, bcdiv($this->money($outstanding), $totalEligible, 8), 8);
            $floor = $this->money($shareExact);
            $remainder = bcsub($shareExact, $floor, 8);
            $parts[$hireId] = [
                'floor' => $floor,
                'remainder' => $remainder,
            ];
            $allocated = bcadd($allocated, $floor, 2);
        }

        $residualUnits = (int) bcmul(bcsub($payment, $allocated, 2), '100', 0);
        if ($residualUnits > 0) {
            uasort($parts, function (array $a, array $b): int {
                return bccomp($b['remainder'], $a['remainder'], 8);
            });

            foreach (array_keys($parts) as $hireId) {
                if ($residualUnits <= 0) {
                    break;
                }
                $parts[$hireId]['floor'] = bcadd($parts[$hireId]['floor'], '0.01', 2);
                $residualUnits--;
            }
        }

        $result = [];
        foreach ($parts as $hireId => $part) {
            if (bccomp($part['floor'], '0', 2) === 1) {
                $result[$hireId] = $part['floor'];
            }
        }

        return $result;
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new BrokerDomainException('A valid monetary amount is required.');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
