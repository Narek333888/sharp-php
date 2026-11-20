<?php

declare(strict_types=1);

namespace SharpPhp;

/**
 * File paths that compare as strings on every system.
 *
 * Windows accepts both "\" and "/", and PHP mixes them there (realpath() gives "C:\app", a directory
 * iterator adds "\Program.sphp" to "C:\app/src"), so a path built one way does not match the same path
 * built another way. SharpPHP writes paths with "/" and compares them through these functions.
 */
final class Paths
{
    /** The path with "/" separators: "C:\app\src/Program.sphp" => "C:/app/src/Program.sphp". */
    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * $path relative to the directory $base ("src/Program.sphp" for "/app/src/Program.sphp" in "/app"),
     * or null when it is not inside it. Windows paths are compared without regard to case.
     */
    public static function relative(string $path, string $base): ?string
    {
        $path = self::normalize($path);
        $base = rtrim(self::normalize($base), '/') . '/';
        $inside = DIRECTORY_SEPARATOR === '\\' ? stripos($path, $base) === 0 : str_starts_with($path, $base);

        return $inside ? substr($path, strlen($base)) : null;
    }
}
