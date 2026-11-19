<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * kind: member (Name = v) | element (v) | pair ({k, v}) | index ([k] = v)
 */
final class InitializerItem extends Node
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $name,
        public readonly ?Expr $key,
        /** @var list<Expr> */
        public readonly array $values,
    ) {
    }
}
