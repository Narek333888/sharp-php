<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Environment
 */
final class Environment
{
    public const string NewLine = PHP_EOL;

    public static function Exit(int $exitCode): never
    {
        exit($exitCode);
    }

    public static function GetEnvironmentVariable(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }

    /** Environment.TickCount: milliseconds since an arbitrary start (for measuring durations). */
    public static function get_TickCount(): int
    {
        return (int) (hrtime(true) / 1_000_000);
    }
}
