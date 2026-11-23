<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\DateTime;

/**
 * System.IO.FileSystemInfo: what FileInfo and DirectoryInfo have in common.
 */
abstract class FileSystemInfo
{
    /** The path as given (new FileInfo("logs/app.log")). */
    protected string $originalPath;

    public function __construct(string $path)
    {
        $this->originalPath = $path;
    }

    /** "app.log" */
    public string $Name {
        get => (string) Path::GetFileName(rtrim($this->originalPath, '/' . DIRECTORY_SEPARATOR));
    }

    /** The absolute path. */
    public string $FullName {
        get => Path::GetFullPath($this->originalPath);
    }

    /** ".log" (with the dot), or "". */
    public string $Extension {
        get => (string) Path::GetExtension($this->originalPath);
    }

    abstract public bool $Exists { get; }

    /** When the file or directory was last changed (local time). */
    public DateTime $LastWriteTime {
        get => file_exists($this->originalPath)
            ? DateTime::$UnixEpoch->AddSeconds((float) filemtime($this->originalPath))->ToLocalTime()
            : DateTime::$UnixEpoch->ToLocalTime();
    }

    public function ToString(): string
    {
        return $this->originalPath;
    }

    public function __toString(): string
    {
        return $this->originalPath;
    }
}
