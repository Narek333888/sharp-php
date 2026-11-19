<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * kind: constant | type | var | discard | not | relational | and | or | property
 *
 * property: "Type { Member: pattern, ... } designation" - $type and $designation are optional,
 * $operands are the subpatterns and $members the member each one applies to.
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
        /** @var list<string> */
        public readonly array $members = [],
        /** @var list<Node> where each member name was written (for diagnostics) */
        public readonly array $memberNodes = [],
    ) {
    }

    /** The expression of a constant or relational pattern. */
    public function expression(): Expr
    {
        return $this->expr ?? throw new \LogicException("A {$this->kind} pattern has no expression");
    }

    /** The type of a type pattern. */
    public function typeRef(): TypeRef
    {
        return $this->type ?? throw new \LogicException("A {$this->kind} pattern has no type");
    }
}
