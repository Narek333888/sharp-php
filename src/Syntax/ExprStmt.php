<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class ExprStmt extends Stmt
{
    public function __construct(
        public readonly Expr $expr,
    ) {
    }
}
