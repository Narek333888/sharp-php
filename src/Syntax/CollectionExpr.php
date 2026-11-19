<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * [1, 2, 3]
 */
final class CollectionExpr extends Expr
{
    public function __construct(
        /** @var list<Expr> */
        public readonly array $elements,
    ) {
    }
}
