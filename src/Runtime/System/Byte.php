<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.Byte (C# byte). Stored as a PHP int.
 */
final class Byte
{
    use IntegerParsing;

    public const int MaxValue = 255;
    public const int MinValue = 0;

    private const NAME = 'an unsigned byte';
}
