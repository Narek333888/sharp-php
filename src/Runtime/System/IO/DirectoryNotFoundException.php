<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.DirectoryNotFoundException: a directory of the path does not exist.
 */
class DirectoryNotFoundException extends IOException
{
    protected const ?string DEFAULT_MESSAGE = 'Attempted to access a path that is not on the disk.';
}
