<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class OverflowException extends ArithmeticException
{
    protected const DEFAULT_MESSAGE = 'Arithmetic operation resulted in an overflow.';
}
