<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * record with { Name = value, ... }
 */
final class WithExpr extends Expr
{
    public function __construct(
        public readonly Expr $target,
        public readonly Initializer $initializer,
    ) {
    }
}
