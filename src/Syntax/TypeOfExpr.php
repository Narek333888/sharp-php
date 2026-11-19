<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * typeof(Type)
 */
final class TypeOfExpr extends Expr
{
    public function __construct(
        public readonly TypeRef $type,
    ) {
    }
}
