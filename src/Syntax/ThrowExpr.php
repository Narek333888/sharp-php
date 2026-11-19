<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * throw used as an expression: value ?? throw new ArgumentNullException(...)
 */
final class ThrowExpr extends Expr
{
    public function __construct(
        public readonly Expr $value,
    ) {
    }
}
