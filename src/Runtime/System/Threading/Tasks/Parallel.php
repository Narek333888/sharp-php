<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading\Tasks;

use SharpPhp\Runtime\System\Threading\CancellationToken;

/**
 * System.Threading.Tasks.Parallel. PHP has one thread: For, ForEach and Invoke run the bodies one after
 * another (which .NET allows: it only promises that they may run in parallel). ForEachAsync runs async
 * bodies concurrently: their waiting (HTTP, timers) overlaps.
 *
 *   Parallel.For(0, 10, i => Process(i));
 *   await Parallel.ForEachAsync(urls, async (url, token) => await http.GetStringAsync(url));
 */
final class Parallel
{
    /** @param \Closure(int): mixed $body */
    public static function For(int $fromInclusive, int $toExclusive, \Closure $body): ParallelLoopResult
    {
        for ($i = $fromInclusive; $i < $toExclusive; $i++) {
            $body($i);
        }

        return new ParallelLoopResult();
    }

    /**
     * @param iterable<mixed> $source
     * @param \Closure(mixed): mixed $body
     */
    public static function ForEach(iterable $source, \Closure $body): ParallelLoopResult
    {
        foreach ($source as $item) {
            $body($item);
        }

        return new ParallelLoopResult();
    }

    /** @param \Closure(): mixed ...$actions */
    public static function Invoke(\Closure ...$actions): void
    {
        foreach ($actions as $action) {
            $action();
        }
    }

    /**
     * Runs the async body for every element at the same time; completes when all have.
     *
     * @param iterable<mixed> $source
     * @param \Closure(mixed, CancellationToken): Task<mixed> $body
     * @return Task<mixed>
     * @sharp-return Task
     */
    public static function ForEachAsync(iterable $source, \Closure $body, ?CancellationToken $cancellationToken = null): Task
    {
        $token = $cancellationToken ?? CancellationToken::$None;
        $tasks = [];
        foreach ($source as $item) {
            $tasks[] = $body($item, $token);
        }

        return Task::WhenAll($tasks);
    }
}
