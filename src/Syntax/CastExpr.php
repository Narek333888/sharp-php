<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * (Type)value
 */
final class CastExpr extends Expr
{
    public function __construct(
        public readonly TypeRef $type,
        public readonly Expr $expr,
    ) {
    }
}
