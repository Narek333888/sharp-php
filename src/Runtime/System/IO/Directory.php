<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.Directory: creating, listing and deleting directories.
 *
 *   Directory.CreateDirectory("logs")     Directory.Exists("logs")
 *   Directory.GetFiles("logs", "*.log")   Directory.Delete("logs", recursive: true)
 */
final class Directory
{
    public static function Exists(?string $path): bool
    {
        return $path !== null && $path !== '' && is_dir($path);
    }

    /** Creates the directory and any missing parents; nothing happens when it exists. */
    public static function CreateDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new IOException("Could not create the directory '$path'.");
        }
    }

    /**
     * The files of the directory (full paths, sorted), matching a pattern with * and ?.
     *
     * @return list<string>
     */
    public static function GetFiles(string $path, string $searchPattern = '*'): array
    {
        return self::entries($path, $searchPattern, files: true);
    }

    /** @return list<string> */
    public static function GetDirectories(string $path, string $searchPattern = '*'): array
    {
        return self::entries($path, $searchPattern, files: false);
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

    public static function GetCurrentDirectory(): string
    {
        return (string) getcwd();
    }

    /** @return list<string> */
    private static function entries(string $path, string $pattern, bool $files): array
    {
        if (!is_dir($path)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
        $result = [];
        foreach (scandir($path) ?: [] as $entry) {
            $full = rtrim($path, '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry;
            if ($entry === '.' || $entry === '..' || is_dir($full) === $files || !fnmatch($pattern, $entry)) {
                continue;
            }
            $result[] = $full;
        }

        return $result;
    }
}
