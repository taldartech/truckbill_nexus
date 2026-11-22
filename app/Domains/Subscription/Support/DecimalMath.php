<?php

namespace App\Domains\Subscription\Support;

use BcMath\Number;
use InvalidArgumentException;

/**
 * Exact decimal arithmetic for subscription money (Rules §15).
 * Never use floating-point for financial totals.
 */
final class DecimalMath
{
    public static function of(int|string $value, int $scale = 2): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                throw new InvalidArgumentException('Invalid decimal value.');
            }
            $number = new Number($trimmed);
        } else {
            $number = new Number((string) $value);
        }

        return self::format($number, $scale);
    }

    public static function add(string $left, string $right, int $scale = 2): string
    {
        return self::format(self::number($left)->add(self::number($right)), $scale);
    }

    public static function sub(string $left, string $right, int $scale = 2): string
    {
        return self::format(self::number($left)->sub(self::number($right)), $scale);
    }

    public static function mul(string $left, string $right, int $scale = 2): string
    {
        return self::format(self::number($left)->mul(self::number($right)), $scale);
    }

    public static function div(string $left, string $right, int $scale = 2): string
    {
        $divisor = self::number($right);
        if ($divisor->compare(new Number('0')) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return self::format(self::number($left)->div($divisor), $scale);
    }

    public static function percentOf(string $amount, string $percent, int $scale = 2): string
    {
        return self::div(self::mul($amount, $percent, 8), '100', $scale);
    }

    public static function applyPercentDiscount(string $amount, string $percent, int $scale = 2): string
    {
        return self::sub($amount, self::percentOf($amount, $percent, $scale), $scale);
    }

    public static function min(string $left, string $right, int $scale = 2): string
    {
        return self::number($left)->compare(self::number($right)) <= 0
            ? self::of($left, $scale)
            : self::of($right, $scale);
    }

    public static function round(string $value, int $scale = 2): string
    {
        return self::of($value, $scale);
    }

    public static function compare(string $left, string $right, int $scale = 2): int
    {
        return self::number(self::of($left, $scale))->compare(self::number(self::of($right, $scale)));
    }

    private static function number(string $value): Number
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw new InvalidArgumentException('Invalid decimal value.');
        }

        return new Number($trimmed);
    }

    private static function format(Number $number, int $scale): string
    {
        return self::number((string) $number->round($scale))->__toString();
    }
}
