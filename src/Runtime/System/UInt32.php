<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.UInt32 (C# uint). Stored as a PHP int.
 */
final class UInt32
{
    use IntegerParsing;

    public const int MaxValue = 4294967295;
    public const int MinValue = 0;

    private const NAME = 'a UInt32';
}
