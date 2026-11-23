<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading;

/**
 * System.Threading.Interlocked: atomic updates of a variable (Interlocked.Increment(ref count)).
 * One thread runs SharpPHP code, so they are plain updates that return what .NET's return.
 */
final class Interlocked
{
    /** Adds 1; returns the new value. */
    public static function Increment(int &$location): int
    {
        return ++$location;
    }

    /** Subtracts 1; returns the new value. */
    public static function Decrement(int &$location): int
    {
        return --$location;
    }

    /** Adds $value; returns the new value. */
    public static function Add(int &$location, int $value): int
    {
        return $location += $value;
    }

    /**
     * Sets $value; returns the old value.
     *
     * @template T
     * @param T $location
     * @param T $value
     * @return T
     */
    public static function Exchange(mixed &$location, mixed $value): mixed
    {
        $old = $location;
        $location = $value;

        return $old;
    }

    /**
     * Sets $value when the variable equals $comparand; returns the old value either way.
     *
     * @template T
     * @param T $location
     * @param T $value
     * @param T $comparand
     * @return T
     */
    public static function CompareExchange(mixed &$location, mixed $value, mixed $comparand): mixed
    {
        $old = $location;
        if ($old === $comparand) {
            $location = $value;
        }

        return $old;
    }
}
