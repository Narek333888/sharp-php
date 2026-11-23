<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.FileNotFoundException: the file does not exist.
 */
class FileNotFoundException extends IOException
{
    protected const ?string DEFAULT_MESSAGE = 'Unable to find the specified file.';
}
