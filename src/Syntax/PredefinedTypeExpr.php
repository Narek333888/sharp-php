<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A keyword type used as an expression: int.Parse, string.Join
 */
final class PredefinedTypeExpr extends Expr
{
    public function __construct(
        public readonly string $keyword,
    ) {
    }
}
