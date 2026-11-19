<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class ConditionalExpr extends Expr
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Expr $then,
        public readonly Expr $else,
    ) {
    }
}
