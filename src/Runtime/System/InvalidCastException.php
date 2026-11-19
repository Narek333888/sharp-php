<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.InvalidCastException: a cast or unboxing to an incompatible type.
 */
class InvalidCastException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Specified cast is not valid.';
}
