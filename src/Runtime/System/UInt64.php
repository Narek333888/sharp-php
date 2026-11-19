<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.UInt64 (C# ulong). Stored as a PHP int, so values are limited to long.MaxValue;
 * ulong.MaxValue (2^64 - 1) does not exist in SharpPHP.
 */
final class UInt64
{
    use IntegerParsing;

    public const int MinValue = 0;

    private const NAME = 'a UInt64';
    private const MaxValue = PHP_INT_MAX;
}
