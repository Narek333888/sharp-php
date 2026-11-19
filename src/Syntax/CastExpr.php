<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class CastExpr extends Expr
{
    public function __construct(
        public readonly TypeRef $type,
        public readonly Expr $expr,
    ) {
    }
}
