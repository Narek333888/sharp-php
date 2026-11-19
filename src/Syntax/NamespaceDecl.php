<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A namespace (file-scoped or block). Global types use an empty name.
 */
final class NamespaceDecl extends Node
{
    public function __construct(
        public readonly string $name,
        /** @var list<UsingDirective> */
        public readonly array $usings,
        /** @var list<TypeDecl> */
        public readonly array $types,
    ) {
    }
}
