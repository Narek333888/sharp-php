<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class AssignExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $target,
        public readonly Expr $value,
    ) {
    }
}
