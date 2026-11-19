<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A parameter of a method, constructor, record or lambda.
 */
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
