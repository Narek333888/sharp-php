<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.UInt16 (C# ushort). Stored as a PHP int.
 */
final class UInt16
{
    use IntegerParsing;

    public const int MaxValue = 65535;
    public const int MinValue = 0;

    private const NAME = 'a UInt16';
}
