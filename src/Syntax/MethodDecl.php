<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * Method, constructor (kind "constructor") or static constructor.
 */
final class MethodDecl extends Node
{
    public function __construct(
        /** @var list<string> */
        public readonly array $modifiers,
        public readonly ?TypeRef $returnType,
        public readonly string $name,
        /** @var list<string> */
        public readonly array $typeParams,
        /** @var list<Parameter> */
        public readonly array $params,
        public readonly ?Block $body,
        public readonly ?Expr $exprBody,
        public readonly string $kind,
        public readonly ?string $initializer,
        /** @var list<Argument> */
        public readonly array $initializerArgs,
        /** @var array<string, list<TypeRef>> */
        public readonly array $constraints,
    ) {
    }
}
