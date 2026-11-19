<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.DivideByZeroException: integer division by zero.
 */
class DivideByZeroException extends ArithmeticException
{
    protected const ?string DEFAULT_MESSAGE = 'Attempted to divide by zero.';
}
