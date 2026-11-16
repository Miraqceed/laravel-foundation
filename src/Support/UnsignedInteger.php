<?php

namespace Miraqceed\Foundation\Support;

use InvalidArgumentException;

/** Exact nonnegative integer arithmetic without optional math extensions. */
final class UnsignedInteger
{
    /**
     * @param int|string $left
     * @param int|string $right
     */
    public static function add(mixed $left, mixed $right): string
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        $carry = 0;
        $result = '';
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry > 0; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0) + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        return strrev($result);
    }

    /**
     * @param int|string $left
     * @param int|string $right
     */
    public static function multiply(mixed $left, mixed $right): string
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $leftLength = strlen($left);
        $rightLength = strlen($right);
        $digits = array_fill(0, $leftLength + $rightLength, 0);
        for ($i = $leftLength - 1; $i >= 0; $i--) {
            $carry = 0;
            for ($j = $rightLength - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $digits[$position] + (int) $left[$i] * (int) $right[$j] + $carry;
                $digits[$position] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
            $digits[$i] = $carry;
        }

        return ltrim(implode('', $digits), '0');
    }

    /**
     * @param int|string $value
     * @param int $scale
     */
    public static function ceilDivideByPowerOfTen(mixed $value, mixed $scale): string
    {
        $value = self::normalize($value);
        if (! is_int($scale) || $scale < 0) {
            throw new InvalidArgumentException('Scale must be a nonnegative integer.');
        }
        if ($scale === 0 || $value === '0') {
            return $value;
        }
        if ($scale >= strlen($value)) {
            return '1';
        }

        $quotient = substr($value, 0, -$scale);
        $remainder = substr($value, -$scale);

        return strspn($remainder, '0') === strlen($remainder) ? $quotient : self::add($quotient, 1);
    }

    /**
     * @param int|string $left
     * @param int|string $right
     */
    public static function compare(mixed $left, mixed $right): int
    {
        $left = self::normalize($left);
        $right = self::normalize($right);
        $lengthComparison = strlen($left) <=> strlen($right);

        return $lengthComparison !== 0 ? $lengthComparison : (strcmp($left, $right) <=> 0);
    }

    private static function normalize(mixed $value): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('Expected a nonnegative integer or ASCII digit string.');
        }

        return ltrim($value, '0') ?: '0';
    }
}
