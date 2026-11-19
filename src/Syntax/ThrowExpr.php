<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class ThrowExpr extends Expr
{
    public function __construct(
        public readonly Expr $value,
    ) {
    }
}
