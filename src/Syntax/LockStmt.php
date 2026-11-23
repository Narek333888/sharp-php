<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * lock (gate) { ... }: SharpPHP code runs on one thread (async code on fibers, which an await
 * inside a lock would let interleave, so C# forbids that and so does the compiler).
 */
final class LockStmt extends Stmt
{
    public function __construct(
        public readonly Expr $target,
        public readonly Stmt $body,
    ) {
    }
}
