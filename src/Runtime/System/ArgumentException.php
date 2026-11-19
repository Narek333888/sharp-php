<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.ArgumentException: an argument is not valid; ParamName names it.
 */
class ArgumentException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Value does not fall within the expected range.';

    public readonly ?string $ParamName;

    public function __construct(?string $message = null, ?string $paramName = null, ?\Throwable $innerException = null)
    {
        $this->ParamName = $paramName;
        $message ??= static::DEFAULT_MESSAGE;
        parent::__construct($paramName !== null ? "$message (Parameter '$paramName')" : $message, $innerException);
    }
}
