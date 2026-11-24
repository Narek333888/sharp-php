<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\DateTime;
use SharpPhp\Runtime\System\Text\Encoding;
use SharpPhp\Runtime\System\Threading\Tasks\Task;

/**
 * System.IO.File: reading and writing whole files. Text is UTF-8 unless an Encoding is given.
 *
 *   File.WriteAllText(path, text)       var text = File.ReadAllText(path)      File.Exists(path)
 *   foreach (var line in File.ReadLines(path)) { ... }                         // one line at a time
 *   await File.WriteAllTextAsync(path, json)                                   byte[] data = File.ReadAllBytes(path)
 *   using var writer = File.AppendText(path)                                    File.Copy / Move / Delete
 */
final class File
{
    /**
     * File.Exists(path): whether a file is there; false (never an exception) for null, "", a
     * directory or a missing path.
     */
    public static function Exists(?string $path): bool
    {
        return $path !== null && $path !== '' && is_file($path);
    }

    /** FileNotFoundException when the file does not exist. */
    public static function ReadAllText(string $path, ?Encoding $encoding = null): string
    {
        $text = self::decode(self::read($path), $encoding);

        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }

    /**
     * The lines, without their line breaks (\n, \r\n or \r).
     *
     * @return list<string>
     */
    public static function ReadAllLines(string $path, ?Encoding $encoding = null): array
    {
        return self::lines(self::ReadAllText($path, $encoding));
    }

    /**
     * The lines one at a time, read while the loop goes on (for big files).
     *
     * @return \Generator<int, string>
     * @sharp-return IEnumerable<string>
     */
    public static function ReadLines(string $path): \Generator
    {
        $reader = new StreamReader($path);
        try {
            while (($line = $reader->ReadLine()) !== null) {
                yield $line;
            }
        } finally {
            $reader->Dispose();
        }
    }

    /**
     * @return list<int>
     * @sharp-return byte[]
     */
    public static function ReadAllBytes(string $path): array
    {
        return Encoding::bytesOf(self::read($path));
    }

    /** Creates or replaces the file. DirectoryNotFoundException when its directory does not exist. */
    public static function WriteAllText(string $path, ?string $contents, ?Encoding $encoding = null): void
    {
        self::write($path, self::encode($contents ?? '', $encoding), 0);
    }

    /** @param iterable<string> $contents each line is followed by a line break */
    public static function WriteAllLines(string $path, iterable $contents, ?Encoding $encoding = null): void
    {
        self::write($path, self::encode(self::joinLines($contents), $encoding), 0);
    }

    /** @param array<int> $bytes */
    public static function WriteAllBytes(string $path, array $bytes): void
    {
        self::write($path, pack('C*', ...$bytes), 0);
    }

    /**
     * File.AppendAllText(path, text): adds the text to the end of the file, creating it, in UTF-8
     * unless an Encoding is given; DirectoryNotFoundException when its directory does not exist.
     */
    public static function AppendAllText(string $path, ?string $contents, ?Encoding $encoding = null): void
    {
        self::write($path, self::encode($contents ?? '', $encoding), FILE_APPEND);
    }

    /** @param iterable<string> $contents */
    public static function AppendAllLines(string $path, iterable $contents, ?Encoding $encoding = null): void
    {
        self::write($path, self::encode(self::joinLines($contents), $encoding), FILE_APPEND);
    }

    /**
     * File.OpenText(path): a StreamReader over the file (UTF-8, a byte order mark is skipped);
     * FileNotFoundException when the file does not exist.
     */
    public static function OpenText(string $path): StreamReader
    {
        return new StreamReader($path);
    }

    /** A writer to a new (or emptied) file. */
    public static function CreateText(string $path): StreamWriter
    {
        return new StreamWriter($path);
    }

    /** A writer that adds to the end of the file (creating it). */
    public static function AppendText(string $path): StreamWriter
    {
        return new StreamWriter($path, append: true);
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

    /**
     * File.Move(source, dest) or Move(source, dest, overwrite: true): moves or renames the file;
     * IOException when dest exists (unless overwrite), FileNotFoundException when source does not.
     */
    public static function Move(string $source, string $destination, bool $overwrite = false): void
    {
        self::requireFile($source);
        if (!$overwrite && file_exists($destination)) {
            throw new IOException('Cannot create a file when that file already exists.');
        }
        if (!@rename($source, $destination)) {
            throw new IOException("Could not move '$source' to '$destination'.");
        }
    }

    /** When the file was last changed (local time); FileNotFoundException when it does not exist. */
    public static function GetLastWriteTime(string $path): DateTime
    {
        self::requireFile($path);

        return DateTime::$UnixEpoch->AddSeconds((float) filemtime($path))->ToLocalTime();
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<string>
     */
    public static function ReadAllTextAsync(string $path, ?Encoding $encoding = null): Task
    {
        return self::now(static fn (): string => self::ReadAllText($path, $encoding));
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<string[]>
     */
    public static function ReadAllLinesAsync(string $path, ?Encoding $encoding = null): Task
    {
        return self::now(static fn (): array => self::ReadAllLines($path, $encoding));
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<byte[]>
     */
    public static function ReadAllBytesAsync(string $path): Task
    {
        return self::now(static fn (): array => self::ReadAllBytes($path));
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task
     */
    public static function WriteAllTextAsync(string $path, ?string $contents, ?Encoding $encoding = null): Task
    {
        return self::now(static fn () => self::WriteAllText($path, $contents, $encoding));
    }

    /**
     * @param iterable<string> $contents
     * @return Task<mixed>
     * @sharp-return Task
     */
    public static function WriteAllLinesAsync(string $path, iterable $contents, ?Encoding $encoding = null): Task
    {
        return self::now(static fn () => self::WriteAllLines($path, $contents, $encoding));
    }

    /**
     * @param array<int> $bytes
     * @return Task<mixed>
     * @sharp-return Task
     */
    public static function WriteAllBytesAsync(string $path, array $bytes): Task
    {
        return self::now(static fn () => self::WriteAllBytes($path, $bytes));
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task
     */
    public static function AppendAllTextAsync(string $path, ?string $contents, ?Encoding $encoding = null): Task
    {
        return self::now(static fn () => self::AppendAllText($path, $contents, $encoding));
    }

    /**
     * The async methods read and write right away (PHP's file functions block) and return a completed
     * task, which carries an exception the way .NET's does: await throws it.
     *
     * @param \Closure(): mixed $work
     * @return Task<mixed>
     */
    private static function now(\Closure $work): Task
    {
        try {
            return Task::FromResult($work());
        } catch (\Throwable $e) {
            return Task::FromException($e);
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
            throw is_dir(dirname($path)) || dirname($path) === '.'
                ? new FileNotFoundException("Could not find file '$path'.")
                : new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
    }

    private static function encode(string $text, ?Encoding $encoding): string
    {
        return $encoding === null || $encoding === Encoding::$UTF8 ? $text : pack('C*', ...$encoding->GetBytes($text));
    }

    private static function decode(string $bytes, ?Encoding $encoding): string
    {
        return $encoding === null || $encoding === Encoding::$UTF8 ? $bytes : $encoding->GetString(Encoding::bytesOf($bytes));
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        if (end($lines) === '') {
            array_pop($lines); // the line break after the last line ends it; it starts no new line
        }

        return $lines;
    }

    /** @param iterable<string> $lines */
    private static function joinLines(iterable $lines): string
    {
        $text = '';
        foreach ($lines as $line) {
            $text .= $line . "\n";
        }

        return $text;
    }
}
