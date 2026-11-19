<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.TimeoutException: an operation ran out of time.
 */
class TimeoutException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'The operation has timed out.';
}
