<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParser;

/**
 * System.UInt64 (C# ulong). Stored as a PHP int, so values are limited to long.MaxValue;
 * ulong.MaxValue (2^64 - 1) does not exist in SharpPHP.
 */
final class UInt64
{
    public const int MinValue = 0;

    /**
     * ulong.Parse(" 42 "): an optional sign and digits, with spaces, tabs or line breaks around (no group separators or
     * decimals); FormatException otherwise, OverflowException outside 0 to long.MaxValue (PHP's int limit).
     */
    public static function Parse(string $s): int
    {
        return IntegerParser::parse($s, self::MinValue, PHP_INT_MAX, 'a UInt64');
    }

    /** @param-out int $result */
    public static function TryParse(?string $s, ?int &$result): bool
    {
        return IntegerParser::tryParse($s, $result, self::MinValue, PHP_INT_MAX);
    }
}
