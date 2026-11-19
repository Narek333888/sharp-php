<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class DoStmt extends Stmt
{
    public function __construct(
        public readonly Stmt $body,
        public readonly Expr $condition,
    ) {
    }
}
