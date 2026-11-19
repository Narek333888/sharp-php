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
    public const float Epsilon = PHP_FLOAT_MIN;
    public const float NaN = NAN;
    public const float PositiveInfinity = INF;
    public const float NegativeInfinity = -INF;

    public static function Parse(string $s): float
    {
        $value = filter_var(trim($s), FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            throw new FormatException("The input string '$s' was not in a correct format.");
        }

        return $value;
    }

    /** @param-out float $result */
    public static function TryParse(?string $s, ?float &$result): bool
    {
        $value = $s === null ? false : filter_var(trim($s), FILTER_VALIDATE_FLOAT);
        $result = $value === false ? 0.0 : $value;

        return $value !== false;
    }

    public static function IsNaN(float $value): bool
    {
        return is_nan($value);
    }

    public static function IsInfinity(float $value): bool
    {
        return is_infinite($value);
    }
}
