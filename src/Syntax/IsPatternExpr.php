<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * value is pattern
 */
final class IsPatternExpr extends Expr
{
    public function __construct(
        public readonly Expr $expr,
        public readonly Pattern $pattern,
    ) {
    }
}
