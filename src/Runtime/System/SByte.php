<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.SByte (C# sbyte). Stored as a PHP int.
 */
final class SByte
{
    use IntegerParsing;

    public const int MaxValue = 127;
    public const int MinValue = -128;

    private const NAME = 'a signed byte';
}
