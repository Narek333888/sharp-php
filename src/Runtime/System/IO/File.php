<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.File: reading and writing whole files (UTF-8).
 *
 *   File.WriteAllText(path, text)     var text = File.ReadAllText(path)     File.Exists(path)
 *   File.ReadAllLines(path)           File.AppendAllText(path, more)        File.Delete(path)
 */
final class File
{
    public static function Exists(?string $path): bool
    {
        return $path !== null && $path !== '' && is_file($path);
    }

    /** FileNotFoundException when the file does not exist. */
    public static function ReadAllText(string $path): string
    {
        $text = self::read($path);

        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }

    /**
     * The lines, without their line breaks (\n, \r\n or \r).
     *
     * @return list<string>
     */
    public static function ReadAllLines(string $path): array
    {
        $text = self::ReadAllText($path);
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        if (end($lines) === '') {
            array_pop($lines); // the line break after the last line ends it; it starts no new line
        }

        return $lines;
    }

    /** Creates or replaces the file. DirectoryNotFoundException when its directory does not exist. */
    public static function WriteAllText(string $path, ?string $contents): void
    {
        self::write($path, $contents ?? '', 0);
    }

    /** @param iterable<string> $contents each line is followed by a line break */
    public static function WriteAllLines(string $path, iterable $contents): void
    {
        self::write($path, self::joinLines($contents), 0);
    }

    public static function AppendAllText(string $path, ?string $contents): void
    {
        self::write($path, $contents ?? '', FILE_APPEND);
    }

    /** @param iterable<string> $contents */
    public static function AppendAllLines(string $path, iterable $contents): void
    {
        self::write($path, self::joinLines($contents), FILE_APPEND);
    }

    /** Deletes the file; nothing happens when it does not exist, as in .NET. */
    public static function Delete(string $path): void
    {
        if (is_file($path) && !@unlink($path)) {
            throw new IOException("Access to the path '$path' is denied.");
        }
    }

    /** IOException when $destination exists and $overwrite is false. */
    public static function Copy(string $source, string $destination, bool $overwrite = false): void
    {
        self::requireFile($source);
        if (!$overwrite && file_exists($destination)) {
            throw new IOException("The file '$destination' already exists.");
        }
        if (!@copy($source, $destination)) {
            throw new IOException("Could not copy '$source' to '$destination'.");
        }
    }

    public static function Move(string $source, string $destination, bool $overwrite = false): void
    {
        self::requireFile($source);
        if (!$overwrite && file_exists($destination)) {
            throw new IOException("Cannot create a file when that file already exists.");
        }
        if (!@rename($source, $destination)) {
            throw new IOException("Could not move '$source' to '$destination'.");
        }
    }

    private static function read(string $path): string
    {
        self::requireFile($path);
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new IOException("Access to the path '$path' is denied.");
        }

        return $text;
    }

    private static function write(string $path, string $contents, int $flags): void
    {
        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
        if (@file_put_contents($path, $contents, $flags) === false) {
            throw new IOException("Access to the path '$path' is denied.");
        }
    }

    private static function requireFile(string $path): void
    {
        if (!is_file($path)) {
            throw new FileNotFoundException("Could not find file '$path'.");
        }
    }

    /** @param iterable<string> $lines */
    private static function joinLines(iterable $lines): string
    {
        $text = '';
        foreach ($lines as $line) {
            $text .= $line . PHP_EOL;
        }

        return $text;
    }
}
