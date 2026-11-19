<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * An expression used as a statement: a call, an assignment, i++.
 */
final class ExprStmt extends Stmt
{
    public function __construct(
        public readonly Expr $expr,
    ) {
    }
}
