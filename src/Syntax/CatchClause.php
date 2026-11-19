<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * catch (Type name) when (filter) { ... }
 */
final class CatchClause extends Node
{
    public function __construct(
        public readonly ?TypeRef $type,
        public readonly ?string $variable,
        public readonly Block $body,
        public readonly ?Expr $when,
    ) {
    }
}
