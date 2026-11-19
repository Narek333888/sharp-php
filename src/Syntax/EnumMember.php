<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A member of an enum: Red or Green = 5.
 */
final class EnumMember extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?Expr $value,
    ) {
    }
}
