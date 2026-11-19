<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * One variable of a declaration: x = 1 in int x = 1, y;
 */
final class VarDeclarator extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?Expr $init,
    ) {
    }
}
