<?php

namespace App\Support;

use InvalidArgumentException;

final class ExactMoney
{
    public static function toMinorUnits(string $money): string
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $money, $matches)) {
            throw new InvalidArgumentException('Invalid monetary value.');
        }

        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return ltrim($whole.$fraction, '0') ?: '0';
    }

    public static function formatMinorUnits(string $minorUnits): string
    {
        $minorUnits = str_pad(ltrim($minorUnits, '0') ?: '0', 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($minorUnits, 0, -2), '0') ?: '0';

        return $whole.'.'.substr($minorUnits, -2);
    }

    public static function add(string $left, string $right): string
    {
        $result = '';
        $carry = 0;
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry
                + ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0);
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    public static function multiply(string $left, int $right): string
    {
        if ($left === '0' || $right === 0) {
            return '0';
        }

        $rightDigits = (string) $right;
        $digits = array_fill(0, strlen($left) + strlen($rightDigits), 0);

        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = strlen($rightDigits) - 1; $rightIndex >= 0; $rightIndex--) {
                $position = $leftIndex + $rightIndex + 1;
                $digits[$position] += (int) $left[$leftIndex] * (int) $rightDigits[$rightIndex];
            }
        }

        for ($index = count($digits) - 1; $index > 0; $index--) {
            $digits[$index - 1] += intdiv($digits[$index], 10);
            $digits[$index] %= 10;
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }
}
