<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * a.b  /  a?.b
 */
final class MemberAccessExpr extends Expr
{
    public function __construct(
        public readonly Expr $target,
        public readonly string $name,
        public readonly bool $nullConditional,
        /** @var list<TypeRef> */
        public readonly array $typeArgs,
    ) {
    }
}
