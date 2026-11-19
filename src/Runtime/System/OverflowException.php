<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.OverflowException: a value does not fit its type (int.Parse("9999999999")).
 */
class OverflowException extends ArithmeticException
{
    protected const ?string DEFAULT_MESSAGE = 'Arithmetic operation resulted in an overflow.';
}
