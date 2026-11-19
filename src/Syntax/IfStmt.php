<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * if (condition) ... else ...
 */
final class IfStmt extends Stmt
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Stmt $then,
        public readonly ?Stmt $else,
    ) {
    }
}
