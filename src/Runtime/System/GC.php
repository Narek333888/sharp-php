<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.GC on PHP's memory management.
 *
 * PHP frees an object as soon as its last reference goes (reference counting), and a cycle collector
 * frees objects that only reference each other. So finalizers (~Name()) run right away instead of on
 * a later collection, Collect() runs the cycle collector, and WaitForPendingFinalizers() has nothing
 * to wait for.
 */
final class GC
{
    /** PHP has one generation. */
    public const int MaxGeneration = 0;

    /** @var \WeakMap<object, true>|null objects whose finalizer must not run */
    private static ?\WeakMap $suppressed = null;

    /** Frees unreachable objects that reference each other (gc_collect_cycles()). */
    public static function Collect(int $generation = 0): void
    {
        gc_collect_cycles();
    }

    /** Bytes of memory the script uses (memory_get_usage()), after a collection when asked. */
    public static function GetTotalMemory(bool $forceFullCollection): int
    {
        if ($forceFullCollection) {
            gc_collect_cycles();
        }

        return memory_get_usage();
    }

    /** How many times the cycle collector has run. */
    public static function CollectionCount(int $generation): int
    {
        return gc_status()['runs'];
    }

    /** Finalizers run when an object is freed, so there are none pending. */
    public static function WaitForPendingFinalizers(): void
    {
    }

    /** Keeps $obj referenced up to this call (it is, as a parameter). */
    public static function KeepAlive(mixed $obj): void
    {
    }

    /** The finalizer of $obj will not run: Dispose() already released what it would. */
    public static function SuppressFinalize(object $obj): void
    {
        self::$suppressed ??= new \WeakMap();
        self::$suppressed[$obj] = true;
    }

    /** Undoes SuppressFinalize(). */
    public static function ReRegisterForFinalize(object $obj): void
    {
        if (self::$suppressed !== null) {
            unset(self::$suppressed[$obj]);
        }
    }

    /** @internal used by compiled finalizers */
    public static function isFinalizerSuppressed(object $obj): bool
    {
        return self::$suppressed !== null && isset(self::$suppressed[$obj]);
    }
}
