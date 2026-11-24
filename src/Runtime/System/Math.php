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

    /**
     * Math.Abs(x): an int stays an int, a double a double (-0.0 gives 0.0). No OverflowException as in .NET:
     * Math.Abs(int.MinValue) is 2147483648, and Math.Abs(long.MinValue) a double.
     */
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

    /** Math.Pow(x, y): IEEE results as in .NET: Pow(-8, 1.0 / 3) is NaN, Pow(0, -1) is ∞, Pow(1, NaN) is 1. */
    public static function Pow(float $x, float $y): float
    {
        return $x ** $y;
    }

    /** Math.Sqrt(x): NaN for a negative x, as in .NET (-0.0 stays -0.0). */
    public static function Sqrt(float $value): float
    {
        return $value < 0 ? NAN : sqrt($value);
    }

    /**
     * Math.Cbrt(x): the cube root, negative for a negative x (Cbrt(-8) is -2). Computed as x to the power 1/3, so
     * unlike .NET a perfect cube can be a last digit off (Cbrt(64) is 3.9999999999999996), and Cbrt(-0.0) is 0.0.
     */
    public static function Cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }

    /** Math.Floor(x): the largest whole number not above x (Floor(-2.5) is -3); NaN and ∞ are unchanged. */
    public static function Floor(float $value): float
    {
        return floor($value);
    }

    /** Math.Ceiling(x): the smallest whole number not below x (Ceiling(-2.5) is -2); NaN and ∞ are unchanged. */
    public static function Ceiling(float $value): float
    {
        return ceil($value);
    }

    /** Math.Truncate(x): x without its fraction, toward zero (Truncate(-2.7) is -2); NaN and ∞ are unchanged. */
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

    /** Math.Sign(x): -1, 0 or 1 (0 for -0.0); ArithmeticException for NaN, as in .NET. */
    public static function Sign(int|float $value): int
    {
        if (is_float($value) && is_nan($value)) {
            throw new ArithmeticException('Function does not accept floating point Not-a-Number values.');
        }

        return $value <=> 0;
    }

    /** Math.Sin(a): the sine of an angle in radians; NaN for an infinite angle or NaN. */
    public static function Sin(float $a): float
    {
        return sin($a);
    }

    /** Math.Cos(a): the cosine of an angle in radians; NaN for an infinite angle or NaN. */
    public static function Cos(float $a): float
    {
        return cos($a);
    }

    /** Math.Tan(a): the tangent of an angle in radians; NaN for an infinite angle or NaN. */
    public static function Tan(float $a): float
    {
        return tan($a);
    }

    /** Math.Atan2(y, x), y first: the angle in radians, from -π to π, of the point (x, y). */
    public static function Atan2(float $y, float $x): float
    {
        return atan2($y, $x);
    }

    /**
     * Math.Log(x): the natural logarithm, -∞ for 0 and NaN for a negative x; Math.Log(x, newBase) in another base
     * (NaN for base 1); a base of 0 or below throws PHP's ValueError (an ArgumentException), where .NET returns NaN.
     */
    public static function Log(float $value, ?float $base = null): float
    {
        return $base === null ? log($value) : log($value, $base);
    }

    /** Math.Log10(x): the base-10 logarithm (Log10(1000) is 3); -∞ for 0, NaN for a negative x. */
    public static function Log10(float $value): float
    {
        return log10($value);
    }

    /** Math.Log2(x): the base-2 logarithm (Log2(8) is 3); -∞ for 0, NaN for a negative x. */
    public static function Log2(float $value): float
    {
        return log($value, 2);
    }

    /** Math.Exp(x): e to the power x; ∞ when that is beyond double's range (Exp(710)). */
    public static function Exp(float $value): float
    {
        return exp($value);
    }
}
