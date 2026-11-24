<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Double (also used for float and decimal).
 */
final class Double
{
    public const float MaxValue = PHP_FLOAT_MAX;
    public const float MinValue = -PHP_FLOAT_MAX;
    /** The smallest positive double (a subnormal one), as .NET's; PHP_FLOAT_MIN is the smallest normal one. */
    public const float Epsilon = 4.9406564584124654E-324;
    public const float NaN = NAN;
    public const float PositiveInfinity = INF;
    public const float NegativeInfinity = -INF;

    /**
     * As .NET's double.Parse (NumberStyles.Float | AllowThousands, en-US): white space around, a leading
     * sign, group separators in the integer part ("1,234.5"), an exponent, "NaN", "∞" and "-∞".
     */
    public static function Parse(string $s): float
    {
        return self::read($s) ?? throw new FormatException("The input string '$s' was not in a correct format.");
    }

    /** @param-out float $result */
    public static function TryParse(?string $s, ?float &$result): bool
    {
        $value = $s === null ? null : self::read($s);
        $result = $value ?? 0.0;

        return $value !== null;
    }

    private static function read(string $s): ?float
    {
        $s = trim($s, " \t\n\r\v\f");
        switch ($s) {
            case 'NaN':
                return NAN;
            case '∞':
            case '+∞':
                return INF;
            case '-∞':
                return -INF;
        }
        if (preg_match('/^[+-]?(?:\d[\d,]*(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', $s) !== 1) {
            return null;
        }
        $value = (float) str_replace(',', '', $s);

        return is_finite($value) ? $value : ($value > 0 ? INF : -INF);
    }

    /** double.IsNaN(x): true only for NaN; test with it, as x == double.NaN is false even when x is NaN. */
    public static function IsNaN(float $value): bool
    {
        return is_nan($value);
    }

    /** double.IsInfinity(x): true for PositiveInfinity and NegativeInfinity, false for NaN. */
    public static function IsInfinity(float $value): bool
    {
        return is_infinite($value);
    }
}
