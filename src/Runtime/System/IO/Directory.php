<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\DateTime;

/**
 * System.IO.Directory: creating, listing, moving and deleting directories.
 *
 *   Directory.CreateDirectory("logs")                     Directory.Exists("logs")
 *   Directory.GetFiles("logs", "*.log")                   Directory.GetFiles("src", "*.sphp", SearchOption.AllDirectories)
 *   Directory.Delete("logs", recursive: true)
 */
final class Directory
{
    /**
     * Directory.Exists(path): whether a directory is there; false (never an exception) for null,
     * "", a file or a missing path.
     */
    public static function Exists(?string $path): bool
    {
        return $path !== null && $path !== '' && is_dir($path);
    }

    /** Creates the directory and any missing parents; nothing happens when it exists. */
    public static function CreateDirectory(string $path): DirectoryInfo
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new IOException("Could not create the directory '$path'.");
        }

        return new DirectoryInfo($path);
    }

    /**
     * The files of the directory (paths starting with $path, sorted by name), matching a pattern with *
     * and ?; with SearchOption.AllDirectories also those of its subdirectories.
     *
     * @return list<string>
     */
    public static function GetFiles(string $path, string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return self::entries($path, $searchPattern, $searchOption, files: true, directories: false);
    }

    /** @return list<string> */
    public static function GetDirectories(string $path, string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return self::entries($path, $searchPattern, $searchOption, files: false, directories: true);
    }

    /** @return list<string> files and directories */
    public static function GetFileSystemEntries(string $path, string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return self::entries($path, $searchPattern, $searchOption, files: true, directories: true);
    }

    /** @return list<string> */
    public static function EnumerateFiles(string $path, string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return self::GetFiles($path, $searchPattern, $searchOption);
    }

    /** @return list<string> */
    public static function EnumerateDirectories(string $path, string $searchPattern = '*', SearchOption $searchOption = SearchOption::TopDirectoryOnly): array
    {
        return self::GetDirectories($path, $searchPattern, $searchOption);
    }

    /** IOException when the directory is not empty, unless $recursive. */
    public static function Delete(string $path, bool $recursive = false): void
    {
        if (!is_dir($path)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
        $entries = array_diff(scandir($path) ?: [], ['.', '..']);
        if ($entries !== [] && !$recursive) {
            throw new IOException("Directory not empty : '$path'");
        }
        foreach ($entries as $entry) {
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) && !is_link($full) ? self::Delete($full, true) : @unlink($full);
        }
        if (!@rmdir($path)) {
            throw new IOException("Could not delete the directory '$path'.");
        }
    }

    /** IOException when the destination exists. */
    public static function Move(string $sourceDirName, string $destDirName): void
    {
        if (!is_dir($sourceDirName)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$sourceDirName'.");
        }
        if (file_exists($destDirName)) {
            throw new IOException("Cannot create '$destDirName' because a file or directory with the same name already exists.");
        }
        if (!@rename($sourceDirName, $destDirName)) {
            throw new IOException("Could not move '$sourceDirName' to '$destDirName'.");
        }
    }

    /**
     * Directory.GetParent(path): the directory that contains path (made absolute first, a trailing
     * separator dropped); null for a root.
     */
    public static function GetParent(string $path): ?DirectoryInfo
    {
        $parent = Path::GetDirectoryName(Path::GetFullPath($path));

        return $parent === null ? null : new DirectoryInfo($parent);
    }

    /** Directory.GetCurrentDirectory(): the working directory of the PHP process, as an absolute path. */
    public static function GetCurrentDirectory(): string
    {
        return (string) getcwd();
    }

    /**
     * Directory.SetCurrentDirectory(path): changes the working directory of the PHP process, which
     * relative paths start from; DirectoryNotFoundException when it cannot.
     */
    public static function SetCurrentDirectory(string $path): void
    {
        if (!@chdir($path)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
    }

    /**
     * Directory.GetLastWriteTime(path): when the directory was last changed, in local time (whole
     * seconds); DirectoryNotFoundException when there is no directory at path.
     */
    public static function GetLastWriteTime(string $path): DateTime
    {
        if (!is_dir($path)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }

        return DateTime::$UnixEpoch->AddSeconds((float) filemtime($path))->ToLocalTime();
    }

    /** @return list<string> */
    private static function entries(string $path, string $pattern, SearchOption $option, bool $files, bool $directories): array
    {
        if (!is_dir($path)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
        $result = [];
        $base = rtrim($path, '/' . DIRECTORY_SEPARATOR);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $base . DIRECTORY_SEPARATOR . $entry;
            $isDir = is_dir($full);
            if (($isDir ? $directories : $files) && fnmatch($pattern, $entry)) {
                $result[] = $full;
            }
            if ($isDir && !is_link($full) && $option === SearchOption::AllDirectories) {
                array_push($result, ...self::entries($full, $pattern, $option, $files, $directories));
            }
        }

        return $result;
    }
}
