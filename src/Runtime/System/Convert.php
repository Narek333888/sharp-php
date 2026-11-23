<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Convert
 */
final class Convert
{
    /** @sharp-char-as-int $value Convert.ToInt32('A') is 65, as in C# */
    private const RANGES = [
        'sbyte' => [-128, 127], 'byte' => [0, 255], 'short' => [-32768, 32767], 'ushort' => [0, 65535],
        'int' => [-2147483648, 2147483647], 'uint' => [0, 4294967295], 'long' => [PHP_INT_MIN, PHP_INT_MAX], 'ulong' => [0, PHP_INT_MAX],
    ];

    /**
     * Rounds doubles to the nearest integer (2.5 => 2); OverflowException out of int's range.
     *
     * @sharp-char-as-int $value Convert.ToInt32('A') is 65, as in C#
     */
    public static function ToInt32(mixed $value): int
    {
        return self::integral($value, 'int', 'an Int32', Int32::Parse(...));
    }

    /**
     * @sharp-return byte
     * @sharp-char-as-int $value
     */
    public static function ToByte(mixed $value): int
    {
        return self::integral($value, 'byte', 'an unsigned byte', Byte::Parse(...));
    }

    /**
     * @sharp-return sbyte
     * @sharp-char-as-int $value
     */
    public static function ToSByte(mixed $value): int
    {
        return self::integral($value, 'sbyte', 'a signed byte', SByte::Parse(...));
    }

    /**
     * @sharp-return short
     * @sharp-char-as-int $value
     */
    public static function ToInt16(mixed $value): int
    {
        return self::integral($value, 'short', 'an Int16', Int16::Parse(...));
    }

    /**
     * @sharp-return ushort
     * @sharp-char-as-int $value
     */
    public static function ToUInt16(mixed $value): int
    {
        return self::integral($value, 'ushort', 'a UInt16', UInt16::Parse(...));
    }

    /**
     * @sharp-return uint
     * @sharp-char-as-int $value
     */
    public static function ToUInt32(mixed $value): int
    {
        return self::integral($value, 'uint', 'a UInt32', UInt32::Parse(...));
    }

    /**
     * @sharp-return long
     * @sharp-char-as-int $value
     */
    public static function ToInt64(mixed $value): int
    {
        return self::integral($value, 'long', 'an Int64', Int64::Parse(...));
    }

    /**
     * @sharp-return ulong
     * @sharp-char-as-int $value
     */
    public static function ToUInt64(mixed $value): int
    {
        return self::integral($value, 'ulong', 'a UInt64', UInt64::Parse(...));
    }

    /**
     * A char from its code (Convert.ToChar(65) is 'A') or a one-character string.
     *
     * @sharp-return char
     * @sharp-char-as-int $value
     */
    public static function ToChar(mixed $value): string
    {
        if (is_string($value)) {
            return mb_strlen($value) === 1 ? $value : throw new FormatException('String must be exactly one character long.');
        }
        $code = self::integral($value, 'ushort', 'a character', static fn (string $s): int => throw new FormatException());

        return mb_chr($code);
    }

    /** @param \Closure(string): int $parse */
    private static function integral(mixed $value, string $type, string $name, \Closure $parse): int
    {
        $number = match (true) {
            is_int($value) => $value,
            is_float($value) => is_finite($value) && abs($value) < 9.3e18 ? (int) round($value, 0, PHP_ROUND_HALF_EVEN) : null,
            is_bool($value) => (int) $value,
            $value === null => 0,
            $value instanceof \BackedEnum => (int) $value->value,
            default => $parse(Op::str($value)),
        };
        [$min, $max] = self::RANGES[$type];
        if ($number === null || $number < $min || $number > $max) {
            throw new OverflowException("Value was either too large or too small for $name.");
        }

        return $number;
    }

    public static function ToDouble(mixed $value): float
    {
        return match (true) {
            is_int($value), is_float($value), is_bool($value) => (float) $value,
            $value === null => 0.0,
            default => Double::Parse(Op::str($value)),
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
