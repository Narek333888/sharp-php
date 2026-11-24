<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

use SharpPhp\Runtime\System\ObjectDisposedException;

/**
 * System.IO.StreamWriter: writes a text file (UTF-8), replacing it or appending to it. What is written
 * reaches the file on Flush() and Dispose() (a using block), or at once with AutoFlush.
 *
 *   using (var writer = new StreamWriter("report.txt"))
 *   {
 *       writer.WriteLine("Name;Total");
 *   }
 */
class StreamWriter extends TextWriter
{
    /** @var resource|null */
    private $handle;

    private string $pending = '';

    /** Write to the file after every Write. */
    public bool $AutoFlush = false;

    public function __construct(string $path, bool $append = false)
    {
        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory)) {
            throw new DirectoryNotFoundException("Could not find a part of the path '$path'.");
        }
        $handle = @fopen($path, $append ? 'ab' : 'wb');
        if ($handle === false) {
            throw new IOException("Access to the path '$path' is denied.");
        }
        $this->handle = $handle;
    }

    protected function append(string $text): void
    {
        if ($this->handle === null) {
            throw new ObjectDisposedException('Cannot write to a closed TextWriter.');
        }
        $this->pending .= $text;
        if ($this->AutoFlush || strlen($this->pending) > 65536) {
            $this->Flush();
        }
    }

    /**
     * writer.Flush(): writes the pending text to the file (Write collects it until Flush, Dispose
     * or 64 KB, or writes at once with AutoFlush).
     */
    public function Flush(): void
    {
        if ($this->handle !== null && $this->pending !== '') {
            fwrite($this->handle, $this->pending);
            fflush($this->handle);
            $this->pending = '';
        }
    }

    /**
     * writer.Dispose() (a using block): writes the pending text and closes the file; Write then
     * throws ObjectDisposedException. A second Dispose does nothing.
     */
    public function Dispose(): void
    {
        $this->Flush();
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * When PHP frees a writer that was not disposed, it disposes it: the pending text still reaches
     * the file, where .NET would lose it.
     */
    public function __destruct()
    {
        // A writer that was never disposed still writes what it has, as .NET's finalizer would not:
        // PHP frees it at a known point, so nothing is lost.
        $this->Dispose();
    }
}
