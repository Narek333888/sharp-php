<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.NotSupportedException: the operation is not supported.
 */
class NotSupportedException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Specified method is not supported.';
}
