<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * $type is null for target-typed new().
 */
final class NewExpr extends Expr
{
    public function __construct(
        public readonly ?TypeRef $type,
        /** @var list<Argument> */
        public readonly array $args,
        public readonly ?Initializer $initializer,
    ) {
    }
}
