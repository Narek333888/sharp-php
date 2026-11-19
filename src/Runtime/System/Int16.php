<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\IntegerParsing;

/**
 * System.Int16 (C# short). Stored as a PHP int.
 */
final class Int16
{
    use IntegerParsing;

    public const int MaxValue = 32767;
    public const int MinValue = -32768;

    private const NAME = 'an Int16';
}
