<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class IfStmt extends Stmt
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Stmt $then,
        public readonly ?Stmt $else,
    ) {
    }
}
