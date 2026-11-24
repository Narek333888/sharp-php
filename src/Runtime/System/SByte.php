<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParser;

/**
 * System.SByte (C# sbyte). Stored as a PHP int.
 */
final class SByte
{
    public const int MaxValue = 127;
    public const int MinValue = -128;

    /**
     * sbyte.Parse(" -42 "): an optional sign and digits, with spaces, tabs or line breaks around (no group separators or
     * decimals); FormatException otherwise, OverflowException outside -128 to 127.
     */
    public static function Parse(string $s): int
    {
        return IntegerParser::parse($s, self::MinValue, self::MaxValue, 'a signed byte');
    }

    /** @param-out int $result */
    public static function TryParse(?string $s, ?int &$result): bool
    {
        return IntegerParser::tryParse($s, $result, self::MinValue, self::MaxValue);
    }
}
