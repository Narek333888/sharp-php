<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class VarDeclarator extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?Expr $init,
    ) {
    }
}
