<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.OperationCanceledException: an operation stopped because its CancellationToken was canceled.
 */
class OperationCanceledException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'The operation was canceled.';
}
