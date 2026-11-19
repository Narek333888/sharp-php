<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.Int32 (C# int). Stored as a PHP int.
 */
final class Int32
{
    use IntegerParsing;

    public const int MaxValue = 2147483647;
    public const int MinValue = -2147483648;

    private const NAME = 'an Int32';
}
