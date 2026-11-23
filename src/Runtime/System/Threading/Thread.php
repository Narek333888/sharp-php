<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading;

use SharpPhp\Runtime\EventLoop;
use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\System\Threading\Tasks\Task;

/**
 * System.Threading.Thread, on SharpPHP's event loop: PHP has one thread, so a Thread is a fiber that
 * runs when the program waits (Join, Thread.Sleep, await) — threads take turns, they do not run at the
 * same instant. Code written for threads works; CPU work is not spread over cores.
 *
 *   var worker = new Thread(() => { Thread.Sleep(100); Console.WriteLine("done"); });
 *   worker.Start();
 *   worker.Join();
 */
class Thread
{
    private static int $nextId = 2;

    private static ?Thread $main = null;

    /** @var Task<mixed>|null */
    private ?Task $task = null;

    public string $Name = '';

    public bool $IsBackground = false;

    public readonly int $ManagedThreadId;

    /** @param \Closure(): mixed $start */
    public function __construct(private readonly ?\Closure $start = null)
    {
        $this->ManagedThreadId = $start === null ? 1 : self::$nextId++;
    }

    public bool $IsAlive {
        get => $this->task !== null && !$this->task->IsCompleted;
    }

    /** Thread.CurrentThread: the thread running now (the main one: SharpPHP has one). */
    public static function get_CurrentThread(): self
    {
        return self::$main ??= new self();
    }

    /** Starts the thread (ThreadStateException when it was started before). */
    public function Start(): void
    {
        if ($this->start === null || $this->task !== null) {
            throw new InvalidOperationException('Thread is running or terminated; it cannot restart.');
        }
        $this->task = Task::Run($this->start);
    }

    /** Waits until the thread has finished (other threads and tasks run meanwhile). */
    public function Join(int $millisecondsTimeout = -1): bool
    {
        if ($this->task === null) {
            throw new InvalidOperationException('Thread has not been started.');
        }
        if ($millisecondsTimeout < 0) {
            $this->task->Wait();

            return true;
        }
        Task::await(Task::WhenAny($this->task, Task::Delay($millisecondsTimeout)));

        return $this->task->IsCompleted;
    }

    /**
     * Pauses the current thread; other threads and tasks run meanwhile (an await inside async code,
     * a wait on the event loop elsewhere).
     */
    public static function Sleep(int $millisecondsTimeout): void
    {
        if ($millisecondsTimeout === 0) {
            EventLoop::currentAsyncFiber() !== null ? Task::await(Task::Delay(0)) : null;

            return;
        }
        Task::await(Task::Delay($millisecondsTimeout));
    }
}
