<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A prefix or postfix operator: -x, !flag, ~bits, ++i, i--.
 */
final class UnaryExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $operand,
        public readonly bool $postfix,
    ) {
    }
}
