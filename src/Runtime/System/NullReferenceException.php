<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.NullReferenceException: a member was used on null.
 */
class NullReferenceException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Object reference not set to an instance of an object.';
}
