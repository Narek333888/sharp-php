<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Diagnostics;

use SharpPhp\Runtime\System\TimeSpan;

/**
 * System.Diagnostics.Stopwatch: measures elapsed time (with hrtime(), nanosecond resolution).
 *
 *   var watch = Stopwatch.StartNew();
 *   ...
 *   watch.Stop();
 *   Console.WriteLine($"{watch.ElapsedMilliseconds} ms");
 */
final class Stopwatch
{
    /** Ticks (100 ns) per second, as in .NET. */
    public const int Frequency = 10_000_000;

    public const bool IsHighResolution = true;

    /** Nanoseconds measured before the current run. */
    private int $elapsed = 0;

    /** hrtime() when the current run started, or null when stopped. */
    private ?int $started = null;

    public bool $IsRunning {
        get => $this->started !== null;
    }

    public TimeSpan $Elapsed {
        get => TimeSpan::FromTicks(intdiv($this->nanoseconds(), 100));
    }

    public int $ElapsedMilliseconds {
        get => intdiv($this->nanoseconds(), 1_000_000);
    }

    public int $ElapsedTicks {
        get => intdiv($this->nanoseconds(), 100);
    }

    public static function StartNew(): self
    {
        $watch = new self();
        $watch->Start();

        return $watch;
    }

    /** The current time in ticks, for measuring without a Stopwatch object. */
    public static function GetTimestamp(): int
    {
        return intdiv(hrtime(true), 100);
    }

    public function Start(): void
    {
        $this->started ??= hrtime(true);
    }

    public function Stop(): void
    {
        if ($this->started !== null) {
            $this->elapsed += hrtime(true) - $this->started;
            $this->started = null;
        }
    }

    /** Stops and sets the elapsed time to zero. */
    public function Reset(): void
    {
        $this->elapsed = 0;
        $this->started = null;
    }

    /** Sets the elapsed time to zero and starts again. */
    public function Restart(): void
    {
        $this->elapsed = 0;
        $this->started = hrtime(true);
    }

    private function nanoseconds(): int
    {
        return $this->elapsed + ($this->started !== null ? hrtime(true) - $this->started : 0);
    }
}
