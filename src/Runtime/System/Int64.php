<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.Int64 (C# long). Stored as a PHP int.
 */
final class Int64
{
    use IntegerParsing;

    public const int MaxValue = PHP_INT_MAX;
    public const int MinValue = PHP_INT_MIN;

    private const NAME = 'an Int64';
}
