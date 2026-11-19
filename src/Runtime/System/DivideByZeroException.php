<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class DivideByZeroException extends ArithmeticException
{
    protected const DEFAULT_MESSAGE = 'Attempted to divide by zero.';
}
