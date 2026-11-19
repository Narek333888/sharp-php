<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * target[index] or target?[index]
 */
final class ElementAccessExpr extends Expr
{
    public function __construct(
        public readonly Expr $target,
        /** @var list<Expr> */
        public readonly array $indexes,
        public readonly bool $nullConditional,
    ) {
    }
}
