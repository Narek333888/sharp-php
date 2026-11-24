<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParser;

/**
 * System.UInt32 (C# uint). Stored as a PHP int.
 */
final class UInt32
{
    public const int MaxValue = 4294967295;
    public const int MinValue = 0;

    /**
     * uint.Parse(" 42 "): an optional sign and digits, with spaces, tabs or line breaks around (no group separators or
     * decimals); FormatException otherwise, OverflowException outside 0 to 4294967295 ("-0" is 0).
     */
    public static function Parse(string $s): int
    {
        return IntegerParser::parse($s, self::MinValue, self::MaxValue, 'a UInt32');
    }

    /** @param-out int $result */
    public static function TryParse(?string $s, ?int &$result): bool
    {
        return IntegerParser::tryParse($s, $result, self::MinValue, self::MaxValue);
    }
}
