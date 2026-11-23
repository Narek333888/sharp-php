<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Single (float): a 32-bit floating-point number. Its values are PHP floats rounded to single
 * precision (16777217f is 16777216).
 */
final class Single
{
    public const float MaxValue = 3.4028234663852886E+38;
    public const float MinValue = -3.4028234663852886E+38;
    /** The smallest positive float (a subnormal one). */
    public const float Epsilon = 1.401298464324817E-45;
    public const float NaN = NAN;
    public const float PositiveInfinity = INF;
    public const float NegativeInfinity = -INF;

    /** As double.Parse, then rounded to single precision. */
    public static function Parse(string $s): float
    {
        return (float) Op::single(Double::Parse($s));
    }

    /** @param-out float $result */
    public static function TryParse(?string $s, ?float &$result): bool
    {
        $ok = Double::TryParse($s, $value);
        $result = (float) Op::single($value);

        return $ok;
    }

    public static function IsNaN(float $value): bool
    {
        return is_nan($value);
    }

    public static function IsInfinity(float $value): bool
    {
        return is_infinite($value);
    }

    public static function IsPositiveInfinity(float $value): bool
    {
        return $value === INF;
    }

    public static function IsNegativeInfinity(float $value): bool
    {
        return $value === -INF;
    }

    public static function IsFinite(float $value): bool
    {
        return is_finite($value);
    }
}
