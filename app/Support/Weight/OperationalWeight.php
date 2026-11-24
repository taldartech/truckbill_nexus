<?php

namespace App\Support\Weight;

use InvalidArgumentException;

/**
 * Decimal-safe conversion between user-facing MT and canonical KG storage.
 *
 * Canonical operational weight storage remains kilograms (3 decimal places).
 * User-facing operational entry/presentation uses Metric Tonnes (MT).
 */
final class OperationalWeight
{
    public const KG_PER_MT = '1000';

    public const MT_SCALE = 3;

    public const KG_SCALE = 3;

    /**
     * @return numeric-string kilograms
     */
    public static function mtToKg(string|float|int|null $mt): string
    {
        if ($mt === null || $mt === '') {
            throw new InvalidArgumentException('Weight in MT is required.');
        }

        $normalized = self::normalize((string) $mt, self::MT_SCALE);

        return self::normalize(bcmul($normalized, self::KG_PER_MT, 6), self::KG_SCALE);
    }

    /**
     * @return numeric-string metric tonnes
     */
    public static function kgToMt(string|float|int|null $kg): string
    {
        if ($kg === null || $kg === '') {
            throw new InvalidArgumentException('Weight in KG is required.');
        }

        $normalized = self::normalize((string) $kg, self::KG_SCALE);

        return self::normalize(bcdiv($normalized, self::KG_PER_MT, 6), self::MT_SCALE);
    }

    /**
     * Nullable helper for optional form fields.
     *
     * @return numeric-string|null kilograms
     */
    public static function optionalMtToKg(string|float|int|null $mt): ?string
    {
        if ($mt === null || $mt === '') {
            return null;
        }

        return self::mtToKg($mt);
    }

    /**
     * Nullable helper for optional presentation fields.
     *
     * @return numeric-string|null metric tonnes
     */
    public static function optionalKgToMt(string|float|int|null $kg): ?string
    {
        if ($kg === null || $kg === '') {
            return null;
        }

        return self::kgToMt($kg);
    }

    /**
     * Actual weight (MT) from units × weight-per-unit (KG).
     *
     * @return numeric-string|null
     */
    public static function actualMtFromUnitsAndWeightPerUnitKg(
        string|float|int|null $units,
        string|float|int|null $weightPerUnitKg,
    ): ?string {
        if ($units === null || $units === '' || $weightPerUnitKg === null || $weightPerUnitKg === '') {
            return null;
        }

        $unitsNorm = self::normalize((string) $units, self::KG_SCALE);
        $wpuNorm = self::normalize((string) $weightPerUnitKg, self::KG_SCALE);
        $actualKg = self::normalize(bcmul($unitsNorm, $wpuNorm, 6), self::KG_SCALE);

        return self::kgToMt($actualKg);
    }

    /**
     * Weight-per-unit (KG) from actual weight (MT) ÷ units.
     *
     * @return numeric-string|null
     */
    public static function weightPerUnitKgFromActualMt(
        string|float|int|null $actualMt,
        string|float|int|null $units,
    ): ?string {
        if ($actualMt === null || $actualMt === '' || $units === null || $units === '') {
            return null;
        }

        $unitsNorm = self::normalize((string) $units, self::KG_SCALE);
        if (bccomp($unitsNorm, '0', self::KG_SCALE) === 0) {
            return null;
        }

        $actualKg = self::mtToKg($actualMt);

        return self::normalize(bcdiv($actualKg, $unitsNorm, 6), self::KG_SCALE);
    }

    /**
     * @return numeric-string
     */
    private static function normalize(string $value, int $scale): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw new InvalidArgumentException('Weight must be a number.');
        }

        $normalized = number_format((float) $trimmed, $scale, '.', '');

        if (bccomp($normalized, '0', $scale) < 0) {
            throw new InvalidArgumentException('Weight must be nonnegative.');
        }

        return $normalized;
    }
}
