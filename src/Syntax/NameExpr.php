<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class NameExpr extends Expr
{
    public function __construct(
        public readonly string $name,
        /** @var list<TypeRef> */
        public readonly array $typeArgs,
    ) {
    }
}
