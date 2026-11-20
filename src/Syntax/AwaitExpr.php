<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * await task: the task's result, once it has completed (only in async methods and lambdas).
 */
final class AwaitExpr extends Expr
{
    public function __construct(
        public readonly Expr $operand,
    ) {
    }
}
