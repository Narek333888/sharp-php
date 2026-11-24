<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParser;

/**
 * System.Int32 (C# int). Stored as a PHP int.
 */
final class Int32
{
    public const int MaxValue = 2147483647;
    public const int MinValue = -2147483648;

    /**
     * int.Parse(" -42 "): an optional sign and digits, with spaces, tabs or line breaks around (no group separators or
     * decimals); FormatException otherwise, OverflowException outside int's range.
     */
    public static function Parse(string $s): int
    {
        return IntegerParser::parse($s, self::MinValue, self::MaxValue, 'an Int32');
    }

    /** @param-out int $result */
    public static function TryParse(?string $s, ?int &$result): bool
    {
        return IntegerParser::tryParse($s, $result, self::MinValue, self::MaxValue);
    }
}
