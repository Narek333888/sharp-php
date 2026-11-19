<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class ArithmeticException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'Overflow or underflow in the arithmetic operation.';
}
