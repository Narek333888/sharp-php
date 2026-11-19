<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * using A.B; / using X = A.B.C;
 */
final class UsingDirective extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $alias,
    ) {
    }
}
