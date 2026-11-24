<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParser;

/**
 * System.Int64 (C# long). Stored as a PHP int.
 */
final class Int64
{
    public const int MaxValue = PHP_INT_MAX;
    public const int MinValue = PHP_INT_MIN;

    /**
     * long.Parse(" -42 "): an optional sign and digits, with spaces, tabs or line breaks around (no group separators or
     * decimals); FormatException otherwise, OverflowException outside long's range.
     */
    public static function Parse(string $s): int
    {
        return IntegerParser::parse($s, self::MinValue, self::MaxValue, 'an Int64');
    }

    /** @param-out int $result */
    public static function TryParse(?string $s, ?int &$result): bool
    {
        return IntegerParser::tryParse($s, $result, self::MinValue, self::MaxValue);
    }
}
