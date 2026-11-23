<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Decimal. Its values are PHP floats (doubles): see the README's notes on decimal.
 */
final class Decimal
{
    /** 79228162514264337593543950335, as near as a double comes. */
    public const float MaxValue = 7.922816251426434E+28;
    public const float MinValue = -7.922816251426434E+28;
    public const float One = 1.0;
    public const float Zero = 0.0;
    public const float MinusOne = -1.0;

    /**
     * As .NET's decimal.Parse (NumberStyles.Number, en-US): white space around, a leading sign, group
     * separators in the integer part ("1,234.50"); no exponent.
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
        if (preg_match('/^[+-]?(?:\d[\d,]*(?:\.\d*)?|\.\d+)$/', $s) !== 1) {
            return null;
        }
        $value = (float) str_replace(',', '', $s);
        if (abs($value) > self::MaxValue) {
            throw new OverflowException('Value was either too large or too small for a Decimal.');
        }

        return $value;
    }
}
