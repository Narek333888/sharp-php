<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\InvalidOperationException;

/**
 * The event loop behind async/await: callbacks ready to run, timers (Task.Delay) and HTTP
 * requests in flight (curl_multi, for HttpClient). Async methods run in PHP Fibers; an await
 * suspends the fiber until its task completes, and the loop resumes it.
 *
 * It is single-threaded, like JavaScript: waiting (timers, network) overlaps, CPU work does not.
 *
 * @internal used by System.Threading.Tasks.Task and System.Net.Http.HttpClient
 */
final class EventLoop
{
    /** @var list<\Closure(): void> */
    private static array $ready = [];

    /** @var array<int, array{0: float, 1: \Closure(): void}> timer id => [due time, callback] */
    private static array $timers = [];

    private static int $nextTimer = 0;

    private static ?\CurlMultiHandle $multi = null;

    /** @var array<int, array{0: \CurlHandle, 1: \Closure(\CurlHandle, int): void}> */
    private static array $requests = [];

    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, true>|null fibers that run async code (an await may suspend them) */
    private static ?\WeakMap $fibers = null;

    public static function defer(\Closure $callback): void
    {
        self::$ready[] = $callback;
    }

    public static function delay(float $seconds, \Closure $callback): void
    {
        self::$timers[self::$nextTimer++] = [microtime(true) + max(0.0, $seconds), $callback];
    }

    /** Runs $request in the background; $done gets the handle and curl's error number (0: success). */
    public static function request(\CurlHandle $request, \Closure $done): void
    {
        self::$multi ??= curl_multi_init();
        curl_multi_add_handle(self::$multi, $request);
        self::$requests[spl_object_id($request)] = [$request, $done];
    }

    /** Starts $body in a new fiber that awaits may suspend; it runs until its first await. */
    public static function start(\Closure $body): void
    {
        $fiber = new \Fiber($body);
        self::$fibers ??= new \WeakMap();
        self::$fibers[$fiber] = true;
        $fiber->start();
    }

    /**
     * The fiber running now, when it runs async code.
     *
     * @return \Fiber<mixed, mixed, mixed, mixed>|null
     */
    public static function currentAsyncFiber(): ?\Fiber
    {
        $fiber = \Fiber::getCurrent();

        return $fiber !== null && isset(self::$fibers[$fiber]) ? $fiber : null;
    }

    /** Runs the loop until $done() is true (a blocking wait: await outside an async method, task.Result). */
    public static function runUntil(\Closure $done): void
    {
        while (!$done()) {
            if (!self::tick()) {
                throw new InvalidOperationException('The task can never complete: nothing is left to run, wait for or download (a deadlock).');
            }
        }
    }

    /** One round of work; false when there is nothing left to do. */
    private static function tick(): bool
    {
        if (self::$ready !== []) {
            $batch = self::$ready;
            self::$ready = [];
            foreach ($batch as $callback) {
                $callback();
            }

            return true;
        }

        $didWork = self::pollRequests();
        $didWork = self::runDueTimers() || $didWork;
        if ($didWork || self::hasReady()) {
            return true;
        }
        if (self::$requests === [] && self::$timers === []) {
            return false;
        }

        // Nothing ready: sleep until the next timer, or until the network has something.
        $next = self::$timers === [] ? null : min(array_map(static fn (array $t): float => $t[0], self::$timers));
        $wait = $next === null ? 0.05 : max(0.0, min(0.05, $next - microtime(true)));
        if (self::$requests !== [] && self::$multi !== null) {
            curl_multi_select(self::$multi, $wait);
        } elseif ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        return true;
    }

    /** Callbacks may have been deferred by the requests and timers that just completed. */
    private static function hasReady(): bool
    {
        return self::$ready !== [];
    }

    private static function runDueTimers(): bool
    {
        $now = microtime(true);
        $due = array_filter(self::$timers, static fn (array $t): bool => $t[0] <= $now);
        if ($due === []) {
            return false;
        }
        uasort($due, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($due as $id => [, $callback]) {
            unset(self::$timers[$id]);
            $callback();
        }

        return true;
    }

    private static function pollRequests(): bool
    {
        $multi = self::$multi;
        if (self::$requests === [] || $multi === null) {
            return false;
        }
        curl_multi_exec($multi, $running);
        $done = false;
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            if (!$handle instanceof \CurlHandle) {
                continue;
            }
            $id = spl_object_id($handle);
            $entry = self::$requests[$id] ?? null;
            unset(self::$requests[$id]);
            curl_multi_remove_handle($multi, $handle);
            if ($entry !== null) {
                $entry[1]($handle, is_int($info['result']) ? $info['result'] : 0);
                $done = true;
            }
        }

        return $done;
    }
}
