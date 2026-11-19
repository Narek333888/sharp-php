<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * An infix operator: a + b, a && b, a ?? b, ...
 */
final class BinaryExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $left,
        public readonly Expr $right,
    ) {
    }
}
