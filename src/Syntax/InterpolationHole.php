<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class InterpolationHole extends Node
{
    public function __construct(
        public readonly Expr $expr,
        public readonly ?string $format,
    ) {
    }
}
