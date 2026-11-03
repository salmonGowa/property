<?php

namespace App\Services;

/**
 * Decimal-string arithmetic helpers for KES amounts (two decimal places, half-up rounding).
 */
final class Money
{
    public static function round(string $amount): string
    {
        $offset = str_starts_with($amount, '-') ? '-0.005' : '0.005';

        return bcadd($amount, $offset, 2);
    }

    public static function multiply(string $amount, string $factor): string
    {
        return self::round(bcmul($amount, $factor, 8));
    }

    /**
     * The given percentage of an amount, e.g. 16% VAT.
     */
    public static function percentOf(string $amount, string $percent): string
    {
        return self::round(bcdiv(bcmul($amount, $percent, 8), '100', 8));
    }

    public static function isPositive(string $amount): bool
    {
        return bccomp($amount, '0', 2) === 1;
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }

    /**
     * @param  iterable<string>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }
}
