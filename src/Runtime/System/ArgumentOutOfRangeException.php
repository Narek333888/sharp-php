<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * Note the C# argument order: (paramName, message).
 */
class ArgumentOutOfRangeException extends ArgumentException
{
    protected const ?string DEFAULT_MESSAGE = 'Specified argument was out of the range of valid values.';

    public function __construct(?string $paramName = null, ?string $message = null)
    {
        parent::__construct($message, $paramName);
    }
}
