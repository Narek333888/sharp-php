<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A type as written in source. Arrays use the name "[]" with the element in $args[0].
 */
final class TypeRef extends Node
{
    public function __construct(
        public readonly string $name,
        /** @var list<TypeRef> */
        public readonly array $args,
        public readonly bool $nullable,
    ) {
    }
}
