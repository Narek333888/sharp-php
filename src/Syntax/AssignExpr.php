<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * An assignment: x = value, x += value, x ??= value.
 */
final class AssignExpr extends Expr
{
    public function __construct(
        public readonly string $operator,
        public readonly Expr $target,
        public readonly Expr $value,
    ) {
    }
}
