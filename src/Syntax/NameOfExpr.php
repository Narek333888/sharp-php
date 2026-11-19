<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * nameof(x)
 */
final class NameOfExpr extends Expr
{
    public function __construct(
        public readonly Expr $expr,
    ) {
    }
}
