<?php

namespace App\Domains\Consignment\Freight;

use App\Domains\Consignment\Enums\FreightRateSemantics;
use App\Domains\Consignment\Enums\FreightRateType;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;

/**
 * Computes goods-row freight_amount from rate type + inputs (Rules §8).
 *
 * Weights are always passed as canonical kilograms. Rate semantics decide whether
 * Per Actual / Per Charged Weight treat the rate as ₹/KG (weight_kg) or ₹/MT (weight_mt).
 */
final class FreightCalculator
{
    public function compute(
        FreightRateType $rateType,
        string $rate,
        ?string $packageCount = null,
        ?string $actualWeight = null,
        ?string $chargedWeight = null,
        FreightRateSemantics $semantics = FreightRateSemantics::WeightKg,
    ): string {
        $rate = $this->normalizeDecimal($rate, 'freight_rate', 4);

        $amount = match ($rateType) {
            FreightRateType::FixedAmount => $rate,
            FreightRateType::PerPackageUnit => $this->multiply(
                $rate,
                $this->requirePositiveInteger($packageCount, 'package_count'),
            ),
            FreightRateType::PerKg => $this->multiply(
                $rate,
                $this->requirePositive($actualWeight, 'actual_weight'),
            ),
            FreightRateType::PerActualWeight => $this->multiply(
                $rate,
                $this->weightBasis($this->requirePositive($actualWeight, 'actual_weight'), $semantics),
            ),
            FreightRateType::PerMt => $this->multiply(
                $rate,
                $this->kgToMt($this->requirePositive($actualWeight, 'actual_weight')),
            ),
            FreightRateType::PerChargedWeight => $this->multiply(
                $rate,
                $this->weightBasis($this->requirePositive($chargedWeight, 'charged_weight'), $semantics),
            ),
        };

        return $this->roundMoney($amount);
    }

    /**
     * @param  numeric-string  $weightKg
     * @return numeric-string
     */
    private function weightBasis(string $weightKg, FreightRateSemantics $semantics): string
    {
        return match ($semantics) {
            FreightRateSemantics::WeightKg => $weightKg,
            FreightRateSemantics::WeightMt => $this->kgToMt($weightKg),
        };
    }

    /**
     * @param  numeric-string  $weightKg
     * @return numeric-string
     */
    private function kgToMt(string $weightKg): string
    {
        return $this->numericString(bcdiv($weightKg, '1000', 6));
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function multiply(string $left, string $right): string
    {
        return $this->numericString(bcmul($left, $right, 6));
    }

    /**
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    private function roundMoney(string $amount): string
    {
        return $this->numericString(number_format((float) $amount, 2, '.', ''));
    }

    /**
     * @return numeric-string
     */
    private function requirePositiveInteger(?string $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new LorryReceiptDomainException("{$field} is required for the selected freight rate type.");
        }

        $trimmed = trim($value);
        if (! preg_match('/^\d+$/', $trimmed) || (int) $trimmed < 1) {
            throw new LorryReceiptDomainException("{$field} must be a positive whole number.");
        }

        return $trimmed;
    }

    /**
     * @return numeric-string
     */
    private function requirePositive(?string $value, string $field): string
    {
        if ($value === null || $value === '') {
            throw new LorryReceiptDomainException("{$field} is required for the selected freight rate type.");
        }

        $normalized = $this->normalizeDecimal($value, $field, 3);
        if (bccomp($normalized, '0', 3) <= 0) {
            throw new LorryReceiptDomainException("{$field} must be greater than zero for the selected freight rate type.");
        }

        return $normalized;
    }

    /**
     * @return numeric-string
     */
    private function normalizeDecimal(string $value, string $field, int $scale): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw new LorryReceiptDomainException("{$field} must be a number.");
        }

        $normalized = $this->numericString(number_format((float) $trimmed, $scale, '.', ''));
        if (bccomp($normalized, '0', $scale) < 0) {
            throw new LorryReceiptDomainException("{$field} must be nonnegative.");
        }

        return $normalized;
    }

    /**
     * @return numeric-string
     */
    private function numericString(string $value): string
    {
        if (! is_numeric($value)) {
            throw new LorryReceiptDomainException('Invalid numeric value.');
        }

        return $value;
    }
}
