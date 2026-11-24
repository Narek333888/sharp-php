<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\IDisposable;
use SharpPhp\Runtime\System\Threading\Tasks\Task;

/**
 * System.IO.TextWriter: writes text (StreamWriter, StringWriter).
 *
 *   using var writer = new StreamWriter("log.txt", append: true);
 *   writer.WriteLine($"{DateTime.Now:HH:mm} started");
 */
abstract class TextWriter implements IDisposable
{
    /** What WriteLine() ends a line with: "\n" (as .NET on Linux and macOS). */
    public string $NewLine = "\n";

    abstract protected function append(string $text): void;

    /**
     * Writes a value as text (true is "True", a double 0.5 is "0.5"), or a composite format: Write("{0} items", n).
     *
     * @sharp-typed-numbers $value
     * @sharp-typed-numbers $args
     */
    public function Write(mixed $value = '', mixed ...$args): void
    {
        $this->append($args !== [] && is_string($value) ? \SharpPhp\Runtime\System\String_::Format($value, ...$args) : Op::str($value));
    }

    /**
     * @sharp-typed-numbers $value
     * @sharp-typed-numbers $args
     */
    public function WriteLine(mixed $value = '', mixed ...$args): void
    {
        $this->Write($value, ...$args);
        $this->append($this->NewLine);
    }

    /** @return Task<mixed> */
    public function WriteAsync(mixed $value = ''): Task
    {
        $this->Write($value);

        return Task::FromResult(null);
    }

    /** @return Task<mixed> */
    public function WriteLineAsync(mixed $value = ''): Task
    {
        $this->WriteLine($value);

        return Task::FromResult(null);
    }

    /**
     * writer.Flush(): passes buffered text on to the destination; StreamWriter writes it to the
     * file, a StringWriter has nothing to flush.
     */
    public function Flush(): void
    {
    }

    /** @return Task<mixed> */
    public function FlushAsync(): Task
    {
        $this->Flush();

        return Task::FromResult(null);
    }

    /** writer.Close(): the same as Dispose(). */
    public function Close(): void
    {
        $this->Dispose();
    }

    /** writer.Dispose() (a using block): flushes the writer; StreamWriter also closes its file. */
    public function Dispose(): void
    {
        $this->Flush();
    }
}
