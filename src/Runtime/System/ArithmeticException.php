<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.ArithmeticException: base class of arithmetic errors.
 */
class ArithmeticException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Overflow or underflow in the arithmetic operation.';
}
