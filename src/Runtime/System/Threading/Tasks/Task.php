<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading\Tasks;

use SharpPhp\Runtime\EventLoop;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\Threading\CancellationToken;

/**
 * System.Threading.Tasks.Task and Task<T>: an operation that completes later.
 *
 *   static async Task<string> LoadAsync(HttpClient http, string url) => await http.GetStringAsync(url);
 *
 *   var pages = await Task.WhenAll(urls.Select(u => LoadAsync(http, u)));   // in parallel
 *   await Task.Delay(500);
 *
 * Async methods run in PHP Fibers on SharpPHP's event loop: while one awaits a timer or the network,
 * the others go on. It is one thread, like JavaScript: waiting overlaps, CPU work does not.
 *
 * @template T
 */
class Task implements \SharpPhp\Runtime\System\IDisposable
{
    /**
     * A task that has already completed.
     *
     * @var Task<mixed>
     */
    public static Task $CompletedTask;

    private const PENDING = 0;
    private const SUCCEEDED = 1;
    private const FAULTED = 2;

    private int $state = self::PENDING;

    /** @var T|null */
    private mixed $result = null;

    private ?\Throwable $error = null;

    /** @var list<\Throwable> every exception of a faulted task (WhenAll gathers those of all its tasks) */
    private array $errors = [];

    private ?\SharpPhp\Runtime\System\AggregateException $aggregate = null;

    /** @var list<\Closure(): void> */
    private array $continuations = [];

    /** True once the task has finished, successfully or not. */
    public bool $IsCompleted {
        get => $this->state !== self::PENDING;
    }

    public bool $IsCompletedSuccessfully {
        get => $this->state === self::SUCCEEDED;
    }

    public bool $IsFaulted {
        get => $this->state === self::FAULTED && !$this->error instanceof \SharpPhp\Runtime\System\OperationCanceledException;
    }

    /** Ended by its CancellationToken (TaskCanceledException). */
    public bool $IsCanceled {
        get => $this->state === self::FAULTED && $this->error instanceof \SharpPhp\Runtime\System\OperationCanceledException;
    }

    /**
     * The exceptions that ended the task, in an AggregateException, as in .NET; null when it did not
     * fail (or was canceled).
     */
    public ?\SharpPhp\Runtime\System\AggregateException $Exception {
        get => $this->error === null || $this->IsCanceled ? null : ($this->aggregate ??= new \SharpPhp\Runtime\System\AggregateException($this->errors));
    }

    /**
     * The result; waits for it when the task is still running. A failed task throws an
     * AggregateException around its exceptions, as in .NET (await throws the exception itself).
     *
     * @var T
     */
    public mixed $Result {
        get => $this->waitFor();
    }

    /**
     * Runs $function as a task. The task's result is what it returns.
     *
     * @template TResult
     * @param \Closure(): TResult $function
     * @return Task<mixed>
     * @sharp-return Task<TResult>
     */
    public static function Run(\Closure $function, ?CancellationToken $cancellationToken = null): self
    {
        $task = new self();
        if ($cancellationToken?->IsCancellationRequested === true) {
            $task->fail(new TaskCanceledException());

            return $task;
        }
        EventLoop::defer(static fn () => EventLoop::start(static fn () => $task->runBody($function)));

        return $task;
    }

    /**
     * A task that completes after $milliseconds.
     *
     * @return Task<mixed>
     * @sharp-return Task
     */
    public static function Delay(int $milliseconds, ?CancellationToken $cancellationToken = null): self
    {
        if ($milliseconds < -1) {
            throw new ArgumentException("The value needs to be either -1 (signifying an infinite timeout), 0 or a positive integer. (Parameter 'millisecondsDelay')");
        }
        $task = new self();
        // A canceled token ends the delay with TaskCanceledException; -1 (Timeout.Infinite) waits for that.
        $cancellationToken?->Register(static fn () => $task->fail(new TaskCanceledException()));
        if ($milliseconds >= 0 && $task->state === self::PENDING) {
            EventLoop::delay($milliseconds / 1000, static fn () => $task->succeed(null));
        }

        return $task;
    }

    /**
     * A completed task with this result.
     *
     * @template TResult
     * @param TResult $result
     * @return Task<mixed>
     * @sharp-return Task<TResult>
     */
    public static function FromResult(mixed $result): self
    {
        $task = new self();
        $task->succeed($result);

        return $task;
    }

    /** @return Task<mixed> */
    public static function FromException(\Throwable $exception): self
    {
        $task = new self();
        $task->fail($exception);

        return $task;
    }

    /**
     * A task that completes when all the tasks have: its result is their results, in order. It fails
     * with the first exception, after all of them finished. Takes tasks, or one collection of tasks.
     *
     * @param mixed ...$tasks
     * @return Task<mixed>
     * @sharp-return Task<object[]>
     */
    public static function WhenAll(mixed ...$tasks): self
    {
        $tasks = self::taskList($tasks);
        $all = new self();
        if ($tasks === []) {
            $all->succeed([]);

            return $all;
        }
        $remaining = count($tasks);
        foreach ($tasks as $task) {
            $task->onComplete(static function () use (&$remaining, $tasks, $all): void {
                if (--$remaining > 0) {
                    return;
                }
                // As .NET: the exceptions of every failed task; canceled only when none failed.
                $errors = [];
                $canceled = null;
                foreach ($tasks as $t) {
                    if ($t->error === null) {
                        continue;
                    }
                    if ($t->IsCanceled) {
                        $canceled ??= $t->error;
                    } else {
                        array_push($errors, ...$t->errors);
                    }
                }
                if ($errors !== []) {
                    $all->failWith($errors);
                } elseif ($canceled !== null) {
                    $all->fail($canceled);
                } else {
                    $all->succeed(array_map(static fn (self $t): mixed => $t->result, $tasks));
                }
            });
        }

        return $all;
    }

    /**
     * A task that completes when the first of the tasks does; its result is that task.
     *
     * @param mixed ...$tasks
     * @return Task<mixed>
     * @sharp-return Task<Task>
     */
    public static function WhenAny(mixed ...$tasks): self
    {
        $tasks = self::taskList($tasks);
        if ($tasks === []) {
            throw new ArgumentException("The tasks argument contains no tasks. (Parameter 'tasks')");
        }
        $any = new self();
        foreach ($tasks as $task) {
            $task->onComplete(static function () use ($task, $any): void {
                if ($any->state === self::PENDING) {
                    $any->succeed($task);
                }
            });
        }

        return $any;
    }

    /** Waits (blocking) until the task has finished; a failed task throws an AggregateException around its exceptions. */
    public function Wait(): void
    {
        $this->waitFor();
    }

    /** @return T */
    private function waitFor(): mixed
    {
        try {
            /** @var T $result */
            $result = self::await($this);

            return $result;
        } catch (\Throwable) {
            throw new \SharpPhp\Runtime\System\AggregateException($this->errors);
        }
    }

    public function ToString(): string
    {
        return 'System.Threading.Tasks.Task';
    }

    /**
     * "await task": the task's result. In an async method the method is suspended until then;
     * elsewhere the event loop runs until the task completes.
     *
     * @internal the compiler emits it for await
     */
    public static function await(mixed $task): mixed
    {
        if (!$task instanceof self) {
            return $task;
        }
        if ($task->state === self::PENDING) {
            $fiber = EventLoop::currentAsyncFiber();
            if ($fiber !== null) {
                $task->onComplete(static fn () => EventLoop::defer(static fn () => $fiber->resume()));
                \Fiber::suspend();
            } else {
                EventLoop::runUntil(static fn (): bool => !$task->isPending());
            }
        }
        if ($task->error !== null) {
            throw $task->error;
        }

        return $task->result;
    }

    /**
     * Runs the body of an async method: synchronously until its first await, as in C#.
     *
     * @internal the compiler emits it for async methods and lambdas
     * @template TResult
     * @param \Closure(): TResult $body
     * @return Task<mixed>
     * @sharp-return Task<TResult>
     */
    public static function async(\Closure $body): self
    {
        $task = new self();
        EventLoop::start(static fn () => $task->runBody($body));

        return $task;
    }

    /**
     * @internal for SharpPHP runtime classes that complete tasks themselves (HttpClient)
     * @return Task<mixed>
     */
    public static function pending(): self
    {
        return new self();
    }

    /** @internal */
    public function succeed(mixed $result): void
    {
        if ($this->state === self::PENDING) {
            $this->state = self::SUCCEEDED;
            $this->result = $result;
            $this->completed();
        }
    }

    /** @internal */
    public function fail(\Throwable $error): void
    {
        $this->failWith([$error]);
    }

    /** @param non-empty-list<\Throwable> $errors */
    private function failWith(array $errors): void
    {
        if ($this->state === self::PENDING) {
            $this->state = self::FAULTED;
            $this->error = $errors[0];
            $this->errors = $errors;
            $this->completed();
        }
    }

    private function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    private function runBody(\Closure $body): void
    {
        try {
            $this->succeed($body());
        } catch (\Throwable $e) {
            $this->fail($e);
        }
    }

    private function onComplete(\Closure $continuation): void
    {
        if ($this->state !== self::PENDING) {
            $continuation();

            return;
        }
        $this->continuations[] = $continuation;
    }

    private function completed(): void
    {
        $continuations = $this->continuations;
        $this->continuations = [];
        foreach ($continuations as $continuation) {
            $continuation();
        }
    }

    /**
     * @param array<mixed> $arguments
     * @return list<self<mixed>>
     */
    private static function taskList(array $arguments): array
    {
        if (count($arguments) === 1 && !$arguments[0] instanceof self && is_iterable($arguments[0])) {
            $arguments = [...$arguments[0]];
        }
        $tasks = [];
        foreach ($arguments as $task) {
            if (!$task instanceof self) {
                throw new ArgumentException("The tasks argument included a null value or something that is not a task. (Parameter 'tasks')");
            }
            $tasks[] = $task;
        }

        return $tasks;
    }

    /** As .NET's: nothing to release in PHP. */
    public function Dispose(): void
    {
    }
}
