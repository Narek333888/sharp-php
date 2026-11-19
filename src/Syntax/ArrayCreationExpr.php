<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * new int[5] / new[] { ... } / { ... }
 */
final class ArrayCreationExpr extends Expr
{
    public function __construct(
        public readonly ?TypeRef $elementType,
        public readonly ?Expr $size,
        /** @var list<Expr>|null */
        public readonly ?array $elements,
    ) {
    }
}
