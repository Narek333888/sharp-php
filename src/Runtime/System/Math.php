<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Math
 */
final class Math
{
    public const float PI = M_PI;
    public const float E = M_E;
    public const float Tau = 2 * M_PI;

    public static function Abs(int|float $value): int|float
    {
        return abs($value);
    }

    public static function Max(int|float $a, int|float $b): int|float
    {
        return max($a, $b);
    }

    public static function Min(int|float $a, int|float $b): int|float
    {
        return min($a, $b);
    }

    public static function Clamp(int|float $value, int|float $min, int|float $max): int|float
    {
        return max($min, min($max, $value));
    }

    public static function Pow(float $x, float $y): float
    {
        return $x ** $y;
    }

    public static function Sqrt(float $value): float
    {
        return $value < 0 ? NAN : sqrt($value);
    }

    public static function Cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }

    public static function Floor(float $value): float
    {
        return floor($value);
    }

    public static function Ceiling(float $value): float
    {
        return ceil($value);
    }

    public static function Truncate(float $value): float
    {
        return $value < 0 ? ceil($value) : floor($value);
    }

    /** Banker's rounding, like C#. */
    public static function Round(float $value, int $digits = 0): float
    {
        return round($value, $digits, PHP_ROUND_HALF_EVEN);
    }

    public static function Sign(int|float $value): int
    {
        return $value <=> 0;
    }

    public static function Sin(float $a): float
    {
        return sin($a);
    }

    public static function Cos(float $a): float
    {
        return cos($a);
    }

    public static function Tan(float $a): float
    {
        return tan($a);
    }

    public static function Atan2(float $y, float $x): float
    {
        return atan2($y, $x);
    }

    public static function Log(float $value, ?float $base = null): float
    {
        return $base === null ? log($value) : log($value, $base);
    }

    public static function Log10(float $value): float
    {
        return log10($value);
    }

    public static function Log2(float $value): float
    {
        return log($value, 2);
    }

    public static function Exp(float $value): float
    {
        return exp($value);
    }
}
