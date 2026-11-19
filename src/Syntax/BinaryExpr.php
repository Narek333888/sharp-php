<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class BinaryExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $left,
        public readonly Expr $right,
    ) {
    }
}
