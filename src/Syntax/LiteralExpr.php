<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * kind: int | float | string | char | bool | null
 */
final class LiteralExpr extends Expr
{
    public function __construct(
        public readonly string $kind,
        public readonly mixed $value,
    ) {
    }
}
