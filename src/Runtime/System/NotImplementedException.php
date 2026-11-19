<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.NotImplementedException: the method has no implementation yet.
 */
class NotImplementedException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'The method or operation is not implemented.';
}
