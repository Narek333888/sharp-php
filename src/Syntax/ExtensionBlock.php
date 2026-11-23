<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * extension(string s) { ... } in a static class (C# 14): its members, already lowered by the parser to
 * static methods of the class. An instance method gets the receiver as its first, "this" parameter
 * (like a classic extension method); a property becomes its getter get_Name(...); a static member keeps
 * its parameters and remembers the type it extends (MethodDecl::$extensionOf).
 */
final class ExtensionBlock extends Node
{
    public function __construct(
        /** @var list<MethodDecl> */
        public readonly array $members,
    ) {
    }
}
