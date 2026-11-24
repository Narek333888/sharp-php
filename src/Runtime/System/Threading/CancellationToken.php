<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading;

use SharpPhp\Runtime\System\OperationCanceledException;

/**
 * System.Threading.CancellationToken: tells an operation that it should stop.
 *
 *   static async Task WorkAsync(CancellationToken token)
 *   {
 *       while (true)
 *       {
 *           token.ThrowIfCancellationRequested();
 *           await Task.Delay(100, token);
 *       }
 *   }
 *
 * @sharp-struct
 */
final class CancellationToken
{
    /** A token that is never canceled (also default(CancellationToken)). */
    public static CancellationToken $None;

    private ?CancellationTokenSource $source = null;

    /** new CancellationToken(true): an already canceled token; false: one that never is (CancellationToken.None). */
    public function __construct(bool $canceled = false)
    {
        if ($canceled) {
            $this->source = new CancellationTokenSource();
            $this->source->Cancel();
        }
    }

    /** @internal CancellationTokenSource.Token */
    public static function of(CancellationTokenSource $source): self
    {
        $token = new self();
        $token->source = $source;

        return $token;
    }

    public bool $IsCancellationRequested {
        get => $this->source !== null && $this->source->IsCancellationRequested;
    }

    public bool $CanBeCanceled {
        get => $this->source !== null;
    }

    /** OperationCanceledException when cancellation was requested. */
    public function ThrowIfCancellationRequested(): void
    {
        if ($this->IsCancellationRequested) {
            throw new OperationCanceledException();
        }
    }

    /**
     * Runs $callback when the token is canceled (right away when it already is).
     *
     * @param \Closure(): void $callback
     */
    public function Register(\Closure $callback): void
    {
        $this->source?->register($callback);
    }

    /**
     * token.Equals(other): true when both come from the same CancellationTokenSource, or neither
     * can be canceled (CancellationToken.None, default).
     */
    public function Equals(mixed $other): bool
    {
        return $other instanceof self && $other->source === $this->source;
    }
}

CancellationToken::$None = new CancellationToken();
