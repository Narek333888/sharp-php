<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A property: auto ({ get; set; }), with accessor bodies, or expression-bodied (=> value).
 */
final class PropertyDecl extends Node
{
    public function __construct(
        /** @var list<string> */
        public readonly array $modifiers,
        public readonly TypeRef $type,
        public readonly string $name,
        /** @var list<Accessor> */
        public readonly array $accessors,
        public readonly ?Expr $exprBody,
        public readonly ?Expr $init,
    ) {
    }
}
