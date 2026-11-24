<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParser;

/**
 * System.Int16 (C# short). Stored as a PHP int.
 */
final class Int16
{
    public const int MaxValue = 32767;
    public const int MinValue = -32768;

    /**
     * short.Parse(" -42 "): an optional sign and digits, with spaces, tabs or line breaks around (no group separators or
     * decimals); FormatException otherwise, OverflowException outside -32768 to 32767.
     */
    public static function Parse(string $s): int
    {
        return IntegerParser::parse($s, self::MinValue, self::MaxValue, 'an Int16');
    }

    /** @param-out int $result */
    public static function TryParse(?string $s, ?int &$result): bool
    {
        return IntegerParser::tryParse($s, $result, self::MinValue, self::MaxValue);
    }
}
