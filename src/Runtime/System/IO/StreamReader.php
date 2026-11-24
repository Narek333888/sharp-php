<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\ObjectDisposedException;

/**
 * System.IO.StreamReader: reads a text file (UTF-8; a byte order mark is skipped).
 *
 *   using (var reader = new StreamReader("data.csv"))
 *   {
 *       while (!reader.EndOfStream) Console.WriteLine(reader.ReadLine());
 *   }
 */
class StreamReader extends TextReader
{
    /** @var resource|null */
    private $handle;

    /** Characters read from the file and not yet returned. */
    private string $buffer = '';

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw is_dir(dirname($path)) || dirname($path) === '.'
                ? new FileNotFoundException("Could not find file '$path'.")
                : new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new IOException("Access to the path '$path' is denied.");
        }
        $this->handle = $handle;
        if (fread($handle, 3) !== "\u{FEFF}") {
            rewind($handle);
        }
    }

    /** True when everything has been read. */
    public bool $EndOfStream {
        get => $this->Peek() === -1;
    }

    /**
     * reader.Peek(): the next character's code without reading it, or -1 at the end of the file
     * (then EndOfStream is true).
     */
    public function Peek(): int
    {
        $this->fill();

        return $this->buffer === '' ? -1 : mb_ord(mb_substr($this->buffer, 0, 1));
    }

    /**
     * reader.Read(): the next character's code, or -1 at the end. A character above U+FFFF is one
     * code point here, not two UTF-16 chars as in .NET.
     */
    public function Read(): int
    {
        $this->fill();
        if ($this->buffer === '') {
            return -1;
        }
        $c = mb_substr($this->buffer, 0, 1);
        $this->buffer = substr($this->buffer, strlen($c));

        return mb_ord($c);
    }

    /**
     * reader.ReadLine(): the next line without its line break (\n, \r\n or \r), "" for an empty
     * line, or null at the end of the file.
     */
    public function ReadLine(): ?string
    {
        $this->fill();
        if ($this->buffer === '') {
            return null;
        }
        // Whole lines at a time: much faster than character by character.
        while (!preg_match('/\r\n|\n|\r(?!$)/', $this->buffer, $m, PREG_OFFSET_CAPTURE) && $this->more()) {
        }
        if (preg_match('/\r\n|\n|\r/', $this->buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
            [$break, $at] = $m[0];
            $line = substr($this->buffer, 0, $at);
            $this->buffer = substr($this->buffer, $at + strlen($break));

            return $line;
        }
        $line = $this->buffer;
        $this->buffer = '';

        return $line;
    }

    /** reader.ReadToEnd(): the rest of the file ("" at the end). */
    public function ReadToEnd(): string
    {
        while ($this->more()) {
        }
        $text = $this->buffer;
        $this->buffer = '';

        return $text;
    }

    /**
     * reader.Dispose() (a using block): closes the file; a later read that needs more of the file
     * throws ObjectDisposedException.
     */
    public function Dispose(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    private function fill(): void
    {
        if ($this->buffer === '') {
            $this->more();
        }
    }

    /** Reads more of the file into the buffer (whole UTF-8 characters); false at the end. */
    private function more(): bool
    {
        if ($this->handle === null) {
            throw new ObjectDisposedException('Cannot read from a closed TextReader.');
        }
        if (feof($this->handle)) {
            return false;
        }
        $chunk = fread($this->handle, 8192);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        // Do not split a UTF-8 character between two reads.
        while (!mb_check_encoding($chunk, 'UTF-8') && !feof($this->handle) && strlen($chunk) < 8196) {
            $next = fread($this->handle, 1);
            if ($next === false || $next === '') {
                break;
            }
            $chunk .= $next;
        }
        $this->buffer .= $chunk;

        return true;
    }
}
