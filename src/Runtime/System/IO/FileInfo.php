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

    /**
     * file.Delete(): deletes the file; nothing happens when it does not exist, as in .NET
     * (IOException when it cannot be deleted).
     */
    public function Delete(): void
    {
        File::Delete($this->originalPath);
    }

    /**
     * file.CopyTo(dest) or CopyTo(dest, overwrite: true): copies the file and returns a FileInfo for the
     * copy; IOException when dest exists (unless overwrite), FileNotFoundException when this file does not.
     */
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

    /**
     * file.OpenText(): a StreamReader over the file (UTF-8, a byte order mark is skipped);
     * FileNotFoundException when it does not exist.
     */
    public function OpenText(): StreamReader
    {
        return new StreamReader($this->originalPath);
    }

    /**
     * file.CreateText(): a StreamWriter to the file, created or emptied (UTF-8);
     * DirectoryNotFoundException when its directory does not exist.
     */
    public function CreateText(): StreamWriter
    {
        return new StreamWriter($this->originalPath);
    }

    /** file.AppendText(): a StreamWriter that adds to the end of the file, creating it (UTF-8). */
    public function AppendText(): StreamWriter
    {
        return new StreamWriter($this->originalPath, append: true);
    }
}
