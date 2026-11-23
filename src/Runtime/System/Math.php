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

    /** As .NET: NaN when either is NaN, and 0.0 is larger than -0.0. */
    public static function Max(int|float $a, int|float $b): int|float
    {
        if (is_int($a) && is_int($b)) {
            return $a >= $b ? $a : $b;
        }
        if (is_nan((float) $a) || is_nan((float) $b)) {
            return NAN;
        }
        if ($a == $b) {
            return self::isNegativeZero($a) ? $b : $a;
        }

        return $a > $b ? $a : $b;
    }

    /** As .NET: NaN when either is NaN, and -0.0 is smaller than 0.0. */
    public static function Min(int|float $a, int|float $b): int|float
    {
        if (is_int($a) && is_int($b)) {
            return $a <= $b ? $a : $b;
        }
        if (is_nan((float) $a) || is_nan((float) $b)) {
            return NAN;
        }
        if ($a == $b) {
            return self::isNegativeZero($a) ? $a : $b;
        }

        return $a < $b ? $a : $b;
    }

    /** ArgumentException when min > max, as in .NET; a NaN value stays NaN. */
    public static function Clamp(int|float $value, int|float $min, int|float $max): int|float
    {
        if ($min > $max) {
            throw new ArgumentException(sprintf("'%s' cannot be greater than %s.", \SharpPhp\Runtime\Op::str($min), \SharpPhp\Runtime\Op::str($max)));
        }
        if (is_float($value) && is_nan($value)) {
            return NAN;
        }

        return $value < $min ? $min : ($value > $max ? $max : $value);
    }

    private static function isNegativeZero(int|float $x): bool
    {
        return is_float($x) && $x === 0.0 && fdiv(1.0, $x) < 0;
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

    /**
     * Banker's rounding by default, like C#: Round(2.5) is 2. Round(x, digits), Round(x, mode),
     * Round(x, digits, mode) with a MidpointRounding (AwayFromZero: Round(2.5) is 3).
     */
    public static function Round(float $value, int|MidpointRounding $digitsOrMode = 0, ?MidpointRounding $mode = null): float
    {
        if ($digitsOrMode instanceof MidpointRounding) {
            [$digits, $mode] = [0, $digitsOrMode];
        } else {
            $digits = $digitsOrMode;
        }
        if ($digits < 0 || $digits > 15) {
            throw new ArgumentOutOfRangeException('digits', 'Rounding digits must be between 0 and 15, inclusive.');
        }

        return match ($mode ?? MidpointRounding::ToEven) {
            MidpointRounding::ToEven => round($value, $digits, PHP_ROUND_HALF_EVEN),
            MidpointRounding::AwayFromZero => round($value, $digits, PHP_ROUND_HALF_UP),
            MidpointRounding::ToZero => round($value, $digits, \RoundingMode::TowardsZero),
            MidpointRounding::ToNegativeInfinity => round($value, $digits, \RoundingMode::NegativeInfinity),
            MidpointRounding::ToPositiveInfinity => round($value, $digits, \RoundingMode::PositiveInfinity),
        };
    }

    public static function Sign(int|float $value): int
    {
        if (is_float($value) && is_nan($value)) {
            throw new ArithmeticException('Function does not accept floating point Not-a-Number values.');
        }

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
