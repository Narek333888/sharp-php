<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.DirectoryInfo: a directory and what to do with it.
 *
 *   var logs = new DirectoryInfo("logs");
 *   foreach (var file in logs.GetFiles("*.log")) Console.WriteLine($"{file.Name} {file.Length}");
 */
class DirectoryInfo extends FileSystemInfo
{
    public bool $Exists {
        get => is_dir($this->originalPath);
    }

    /** The parent directory, or null for a root. */
    public ?DirectoryInfo $Parent {
        get => ($parent = Path::GetDirectoryName($this->FullName)) === null ? null : new DirectoryInfo($parent);
    }

    /** Creates the directory (and its parents); nothing happens when it exists. */
    public function Create(): void
    {
        Directory::CreateDirectory($this->originalPath);
    }

    public function CreateSubdirectory(string $path): DirectoryInfo
    {
        return Directory::CreateDirectory(Path::Combine($this->originalPath, $path));
    }

    public function Delete(bool $recursive = false): void
    {
        Directory::Delete($this->originalPath, $recursive);
    }

    /** @return list<FileInfo> */
    public function GetFiles(string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return array_map(static fn (string $f): FileInfo => new FileInfo($f), Directory::GetFiles($this->originalPath, $searchPattern, $searchOption));
    }

    /** @return list<DirectoryInfo> */
    public function GetDirectories(string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return array_map(static fn (string $d): DirectoryInfo => new DirectoryInfo($d), Directory::GetDirectories($this->originalPath, $searchPattern, $searchOption));
    }

    /** @return list<FileInfo> */
    public function EnumerateFiles(string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return $this->GetFiles($searchPattern, $searchOption);
    }

    public function MoveTo(string $destDirName): void
    {
        Directory::Move($this->originalPath, $destDirName);
        $this->originalPath = $destDirName;
    }
}
