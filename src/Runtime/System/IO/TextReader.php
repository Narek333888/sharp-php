<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\IDisposable;
use SharpPhp\Runtime\System\Threading\Tasks\Task;

/**
 * System.IO.TextReader: reads text a character or a line at a time (StreamReader, StringReader).
 *
 *   using var reader = new StreamReader("data.txt");
 *   string? line;
 *   while ((line = reader.ReadLine()) != null) { ... }
 */
abstract class TextReader implements IDisposable
{
    /** The next character's code without reading it, or -1 at the end. */
    abstract public function Peek(): int;

    /** The next character's code, or -1 at the end. */
    abstract public function Read(): int;

    /** The next line without its line break (\n, \r\n or \r), or null at the end. */
    public function ReadLine(): ?string
    {
        if ($this->Peek() === -1) {
            return null;
        }
        $line = '';
        while (($c = $this->Read()) !== -1) {
            if ($c === 10) {
                break;
            }
            if ($c === 13) {
                if ($this->Peek() === 10) {
                    $this->Read();
                }
                break;
            }
            $line .= mb_chr($c);
        }

        return $line;
    }

    /** The rest of the text. */
    public function ReadToEnd(): string
    {
        $text = '';
        while (($c = $this->Read()) !== -1) {
            $text .= mb_chr($c);
        }

        return $text;
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<string?>
     */
    public function ReadLineAsync(): Task
    {
        return Task::FromResult($this->ReadLine());
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<string>
     */
    public function ReadToEndAsync(): Task
    {
        return Task::FromResult($this->ReadToEnd());
    }

    /** reader.Close(): the same as Dispose(). */
    public function Close(): void
    {
        $this->Dispose();
    }

    /**
     * reader.Dispose() (a using block): releases what the reader holds: StreamReader closes its
     * file, a StringReader holds nothing.
     */
    public function Dispose(): void
    {
    }
}
