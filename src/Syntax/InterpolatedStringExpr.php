<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * An interpolated string: $"Hello {name}!" (text parts and holes).
 */
final class InterpolatedStringExpr extends Expr
{
    public function __construct(
        /** @var list<string|InterpolationHole> */
        public readonly array $parts,
    ) {
    }
}
