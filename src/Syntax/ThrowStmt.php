<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * "throw;" (rethrow) has a null expression.
 */
final class ThrowStmt extends Stmt
{
    public function __construct(
        public readonly ?Expr $value,
    ) {
    }
}
