<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Convert
 */
final class Convert
{
    public static function ToInt32(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) round($value, 0, PHP_ROUND_HALF_EVEN),
            is_bool($value) => (int) $value,
            $value === null => 0,
            $value instanceof \BackedEnum => (int) $value->value,
            default => Int32::Parse((string) $value),
        };
    }

    public static function ToDouble(mixed $value): float
    {
        return match (true) {
            is_int($value), is_float($value), is_bool($value) => (float) $value,
            $value === null => 0.0,
            default => Double::Parse((string) $value),
        };
    }

    public static function ToBoolean(mixed $value): bool
    {
        return is_string($value) ? Boolean::Parse($value) : (bool) $value;
    }

    public static function ToString(mixed $value): string
    {
        return Op::str($value);
    }
}
