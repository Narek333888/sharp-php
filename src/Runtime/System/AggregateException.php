<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\System\Collections\Generic\List_;

/**
 * System.AggregateException: one or more exceptions together. Task.Wait() and task.Result throw it
 * around what the task threw (await throws the exception itself):
 *
 *   try { task.Wait(); }
 *   catch (AggregateException e) { foreach (var inner in e.InnerExceptions) Console.WriteLine(inner.Message); }
 *
 * new AggregateException(), (message), (inner1, inner2, ...), (list of exceptions),
 * (message, inner1, ...), (message, list of exceptions).
 */
class AggregateException extends Exception
{
    /** @var list<\Throwable> */
    private readonly array $inner;

    /** The message without the inner exceptions' ones. */
    private readonly string $baseMessage;

    /**
     * The exceptions it holds, in order.
     *
     * @var List_<\Throwable>
     */
    public List_ $InnerExceptions {
        get => new List_($this->inner);
    }

    public function __construct(mixed $messageOrExceptions = null, mixed ...$exceptions)
    {
        $message = is_string($messageOrExceptions) ? $messageOrExceptions : null;
        $items = $message === null && $messageOrExceptions !== null ? [$messageOrExceptions, ...$exceptions] : $exceptions;
        $inner = [];
        foreach ($items as $item) {
            foreach (is_iterable($item) ? $item : [$item] as $exception) {
                if (!$exception instanceof \Throwable) {
                    throw new ArgumentException('An element of innerExceptions was null.', 'innerExceptions');
                }
                $inner[] = $exception;
            }
        }
        $this->inner = $inner;
        $this->baseMessage = $message ?? 'One or more errors occurred.';
        // As .NET: the message, then each inner exception's message in parentheses.
        $text = $this->baseMessage;
        foreach ($inner as $exception) {
            $text .= ' (' . $exception->getMessage() . ')';
        }
        parent::__construct($text, $inner[0] ?? null);
    }

    /** The inner exceptions, with those of inner AggregateExceptions in their place. */
    public function Flatten(): AggregateException
    {
        $flat = [];
        $pending = $this->inner;
        while ($pending !== []) {
            $exception = array_shift($pending);
            if ($exception instanceof self) {
                array_push($pending, ...$exception->inner);
            } else {
                $flat[] = $exception;
            }
        }

        return new self($this->baseMessage, $flat);
    }

    /**
     * Calls $predicate for each inner exception; those it returns false for are thrown again in a new
     * AggregateException.
     *
     * @param \Closure(\Throwable): bool $predicate
     */
    public function Handle(\Closure $predicate): void
    {
        $unhandled = array_values(array_filter($this->inner, static fn (\Throwable $e): bool => !$predicate($e)));
        if ($unhandled !== []) {
            // As .NET, the new exception's message starts with this one's whole message.
            throw new self($this->getMessage(), $unhandled);
        }
    }

    /** The innermost exception that is not an AggregateException with exactly one inner exception. */
    public function GetBaseException(): \Throwable
    {
        $current = $this;
        while ($current instanceof self && count($current->inner) === 1) {
            $current = $current->inner[0];
        }

        return $current;
    }
}
