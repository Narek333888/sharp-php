<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * class / struct / interface / record / enum.
 */
final class TypeDecl extends Node
{
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        /** @var list<string> */
        public readonly array $modifiers,
        /** @var list<string> */
        public readonly array $typeParams,
        /** @var list<TypeRef> */
        public readonly array $baseTypes,
        /** @var list<Node> */
        public readonly array $members,
        /** @var list<Parameter> */
        public readonly array $recordParams,
        /** @var list<EnumMember> */
        public readonly array $enumMembers,
        /** @var array<string, list<TypeRef>> */
        public readonly array $constraints,
        /** @var array<string, 'in'|'out'> variant type parameters of an interface: IEnumerable<out T> */
        public readonly array $variance = [],
    ) {
    }
}
