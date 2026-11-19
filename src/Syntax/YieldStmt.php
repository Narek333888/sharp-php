<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * "yield break;" has a null value.
 */
final class YieldStmt extends Stmt
{
    public function __construct(
        public readonly ?Expr $value,
    ) {
    }
}
