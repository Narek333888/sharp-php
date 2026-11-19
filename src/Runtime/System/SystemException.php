<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.SystemException: base class of the exceptions the runtime throws.
 */
class SystemException extends Exception
{
    protected const ?string DEFAULT_MESSAGE = 'System error.';
}
