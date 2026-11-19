<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class DefaultExpr extends Expr
{
    public function __construct(
        public readonly ?TypeRef $type,
    ) {
    }
}
