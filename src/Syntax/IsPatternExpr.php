<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class IsPatternExpr extends Expr
{
    public function __construct(
        public readonly Expr $expr,
        public readonly Pattern $pattern,
    ) {
    }
}
