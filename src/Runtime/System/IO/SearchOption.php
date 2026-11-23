<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.SearchOption: Directory.GetFiles(path, "*.log", SearchOption.AllDirectories) also searches
 * the subdirectories.
 */
enum SearchOption: int
{
    case TopDirectoryOnly = 0;
    case AllDirectories = 1;
}
