<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class WhileStmt extends Stmt
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Stmt $body,
    ) {
    }
}
