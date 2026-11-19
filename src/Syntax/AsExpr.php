<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * value as Type
 */
final class AsExpr extends Expr
{
    public function __construct(
        public readonly Expr $expr,
        public readonly TypeRef $type,
    ) {
    }
}
