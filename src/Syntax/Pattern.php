<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * kind: constant | type | var | discard | not | relational | and | or
 */
final class Pattern extends Node
{
    public function __construct(
        public readonly string $kind,
        public readonly ?Expr $expr,
        public readonly ?TypeRef $type,
        public readonly ?string $designation,
        public readonly ?string $operator,
        /** @var list<Pattern> */
        public readonly array $operands,
    ) {
    }
}
