<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * Note the C# argument order: (paramName, message).
 */
class ArgumentNullException extends ArgumentException
{
    protected const ?string DEFAULT_MESSAGE = 'Value cannot be null.';

    public function __construct(?string $paramName = null, ?string $message = null)
    {
        parent::__construct($message, $paramName);
    }

    /** @sharp-caller-argument-expression $paramName argument */
    public static function ThrowIfNull(mixed $argument, ?string $paramName = null): void
    {
        if ($argument === null) {
            throw new self($paramName); // (paramName, message), as in C#
        }
    }
}
