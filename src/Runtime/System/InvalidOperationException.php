<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.InvalidOperationException: the call is not valid in the object's current state (e.g. First() on an empty sequence).
 */
class InvalidOperationException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Operation is not valid due to the current state of the object.';
}
