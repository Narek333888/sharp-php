<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.Path: file and directory names.
 *
 *   Path.Combine("logs", "app.log")          Path.GetFileName("/a/b.txt")   // "b.txt"
 *   Path.GetExtension("b.txt")   // ".txt"   Path.GetTempPath()
 */
final class Path
{
    public const string DirectorySeparatorChar = DIRECTORY_SEPARATOR;

    public const string PathSeparator = PATH_SEPARATOR;

    /** Joins the parts with the directory separator; a rooted part ("/etc") starts over from it. */
    public static function Combine(string ...$paths): string
    {
        $result = '';
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            if (self::IsPathRooted($path) || $result === '') {
                $result = $path;
            } elseif (str_ends_with($result, '/') || str_ends_with($result, DIRECTORY_SEPARATOR)) {
                $result .= $path;
            } else {
                $result .= DIRECTORY_SEPARATOR . $path;
            }
        }

        return $result;
    }

    /** "b.txt" for "/a/b.txt"; "" for a path that ends with a separator. */
    public static function GetFileName(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $position = max((int) strrpos($path, '/'), (int) strrpos($path, DIRECTORY_SEPARATOR));

        return str_contains($path, '/') || str_contains($path, DIRECTORY_SEPARATOR) ? substr($path, $position + 1) : $path;
    }

    public static function GetFileNameWithoutExtension(?string $path): ?string
    {
        $name = self::GetFileName($path);
        if ($name === null) {
            return null;
        }
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, 0, $dot);
    }

    /** ".txt" (with the dot), or "" when the file name has none. */
    public static function GetExtension(?string $path): ?string
    {
        $name = self::GetFileName($path);
        if ($name === null) {
            return null;
        }
        $dot = strrpos($name, '.');

        return $dot === false || $dot === strlen($name) - 1 ? '' : substr($name, $dot);
    }

    public static function HasExtension(?string $path): bool
    {
        return ($path !== null) && self::GetExtension($path) !== '';
    }

    /** "/a" for "/a/b.txt"; "" for a bare file name; null for a root or null. */
    public static function GetDirectoryName(?string $path): ?string
    {
        if ($path === null || $path === '' || $path === '/' || $path === DIRECTORY_SEPARATOR) {
            return null;
        }
        $name = self::GetFileName($path);
        $directory = substr($path, 0, strlen($path) - strlen((string) $name));
        $trimmed = rtrim($directory, '/' . DIRECTORY_SEPARATOR);

        return $trimmed === '' && $directory !== '' ? substr($directory, 0, 1) : $trimmed;
    }

    public static function ChangeExtension(?string $path, ?string $extension): ?string
    {
        if ($path === null) {
            return null;
        }
        $current = (string) self::GetExtension($path);
        $base = $current === '' ? rtrim($path, '.') : substr($path, 0, -strlen($current));
        if ($extension === null) {
            return $base;
        }

        return $base . (str_starts_with($extension, '.') || $extension === '' ? $extension : '.' . $extension);
    }

    /** The absolute path: relative to the current directory, with "." and ".." resolved. */
    public static function GetFullPath(string $path): string
    {
        $absolute = self::IsPathRooted($path) ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
        $parts = [];
        foreach (preg_split('#[/\\\\]+#', $absolute) ?: [] as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $root = preg_match('#^[A-Za-z]:#', $absolute) === 1 ? '' : DIRECTORY_SEPARATOR;

        return $root . implode(DIRECTORY_SEPARATOR, $parts);
    }

    public static function IsPathRooted(?string $path): bool
    {
        return $path !== null && ($path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:#', $path) === 1));
    }

    /** The temporary directory, ending with a separator ("/tmp/"). */
    public static function GetTempPath(): string
    {
        return rtrim(sys_get_temp_dir(), '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /** A random file name (8.3), not created. */
    public static function GetRandomFileName(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $name = '';
        for ($i = 0; $i < 11; $i++) {
            $name .= $chars[random_int(0, 35)];
        }

        return substr($name, 0, 8) . '.' . substr($name, 8);
    }
}
