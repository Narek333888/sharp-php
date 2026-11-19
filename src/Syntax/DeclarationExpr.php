<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * out var x / out int x
 */
final class DeclarationExpr extends Expr
{
    public function __construct(
        public readonly ?TypeRef $type,
        public readonly string $name,
    ) {
    }
}
