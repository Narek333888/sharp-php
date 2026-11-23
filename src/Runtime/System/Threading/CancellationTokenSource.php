<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading;

use SharpPhp\Runtime\EventLoop;
use SharpPhp\Runtime\System\IDisposable;

/**
 * System.Threading.CancellationTokenSource: cancels the operations that got its Token.
 *
 *   using var cts = new CancellationTokenSource();
 *   cts.CancelAfter(500);
 *   await Task.Delay(10_000, cts.Token);   // TaskCanceledException after 500 ms
 */
class CancellationTokenSource implements IDisposable
{
    private bool $canceled = false;

    /** @var list<\Closure(): void> */
    private array $callbacks = [];

    public function __construct(int $millisecondsDelay = -1)
    {
        if ($millisecondsDelay >= 0) {
            $this->CancelAfter($millisecondsDelay);
        }
    }

    public bool $IsCancellationRequested {
        get => $this->canceled;
    }

    public CancellationToken $Token {
        get => CancellationToken::of($this);
    }

    /** Cancels: the token's callbacks run (most recently registered first), awaiting operations stop. */
    public function Cancel(): void
    {
        if ($this->canceled) {
            return;
        }
        $this->canceled = true;
        $callbacks = array_reverse($this->callbacks);
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    /** Cancels after $milliseconds (on the event loop: while the program awaits or waits). */
    public function CancelAfter(int $milliseconds): void
    {
        EventLoop::delay($milliseconds / 1000, fn () => $this->Cancel());
    }

    /** A source canceled when any of the tokens is. */
    public static function CreateLinkedTokenSource(CancellationToken ...$tokens): self
    {
        $linked = new self();
        foreach ($tokens as $token) {
            $token->Register(static fn () => $linked->Cancel());
        }

        return $linked;
    }

    public function Dispose(): void
    {
        $this->callbacks = [];
    }

    /**
     * @internal CancellationToken.Register()
     * @param \Closure(): void $callback
     */
    public function register(\Closure $callback): void
    {
        if ($this->canceled) {
            $callback();

            return;
        }
        $this->callbacks[] = $callback;
    }
}
