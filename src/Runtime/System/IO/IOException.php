<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\SystemException;

/**
 * System.IO.IOException: a file or directory operation failed.
 */
class IOException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'I/O error occurred.';
}
