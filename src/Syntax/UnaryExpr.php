<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class UnaryExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $operand,
        public readonly bool $postfix,
    ) {
    }
}
