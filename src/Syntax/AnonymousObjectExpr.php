<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * new { Id = 1, name, user.Email }: an object of an anonymous type with read-only properties.
 * $names[$i] is the property that $values[$i] initializes (inferred for "name" and "user.Email").
 */
final class AnonymousObjectExpr extends Expr
{
    public function __construct(
        /** @var list<string> */
        public readonly array $names,
        /** @var list<Expr> */
        public readonly array $values,
    ) {
    }
}
