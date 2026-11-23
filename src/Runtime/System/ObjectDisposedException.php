<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.ObjectDisposedException: an object was used after Dispose() (a closed reader or writer).
 */
class ObjectDisposedException extends InvalidOperationException
{
    protected const ?string DEFAULT_MESSAGE = 'Cannot access a disposed object.';
}
