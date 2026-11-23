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
        /** A static member of an extension block (C# 14): the type it extends, as in string.Repeat(...). */
        public readonly ?TypeRef $extensionOf = null,
        /** A property of an extension block, lowered to its getter get_Name(): the property's name. */
        public readonly ?string $extensionProperty = null,
        /** A local function, compiled to a private method of the class: its name as written. */
        public readonly bool $isLocalFunction = false,
    ) {
    }

    /**
     * For a local function: the variables and parameters of the code around it, which it cannot use
     * (it is compiled to a method). Set when the enclosing member has been parsed.
     *
     * @var list<string>
     */
    public array $enclosingLocals = [];
}
