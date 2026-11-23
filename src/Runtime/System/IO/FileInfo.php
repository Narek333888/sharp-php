<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.FileInfo: a file and what to do with it.
 *
 *   var info = new FileInfo("report.csv");
 *   if (info.Exists) Console.WriteLine($"{info.Name}: {info.Length} bytes");
 */
class FileInfo extends FileSystemInfo
{
    public bool $Exists {
        get => is_file($this->originalPath);
    }

    /** The size in bytes; FileNotFoundException when the file does not exist. */
    public int $Length {
        get => is_file($this->originalPath) ? (int) filesize($this->originalPath) : throw new FileNotFoundException("Could not find file '{$this->FullName}'.");
    }

    /** The directory's path. */
    public ?string $DirectoryName {
        get => Path::GetDirectoryName($this->FullName);
    }

    public ?DirectoryInfo $Directory {
        get => $this->DirectoryName === null ? null : new DirectoryInfo($this->DirectoryName);
    }

    public bool $IsReadOnly {
        get => is_file($this->originalPath) && !is_writable($this->originalPath);
    }

    public function Delete(): void
    {
        File::Delete($this->originalPath);
    }

    public function CopyTo(string $destFileName, bool $overwrite = false): FileInfo
    {
        File::Copy($this->originalPath, $destFileName, $overwrite);

        return new FileInfo($destFileName);
    }

    /** Moves the file; this FileInfo then stands for the new path. */
    public function MoveTo(string $destFileName, bool $overwrite = false): void
    {
        File::Move($this->originalPath, $destFileName, $overwrite);
        $this->originalPath = $destFileName;
    }

    public function OpenText(): StreamReader
    {
        return new StreamReader($this->originalPath);
    }

    public function CreateText(): StreamWriter
    {
        return new StreamWriter($this->originalPath);
    }

    public function AppendText(): StreamWriter
    {
        return new StreamWriter($this->originalPath, append: true);
    }
}
