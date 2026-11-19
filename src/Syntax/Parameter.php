<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class Parameter extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?TypeRef $type,
        public readonly ?Expr $default,
        public readonly ?string $modifier,
    ) {
    }
}
