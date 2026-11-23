<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A {value} or {value:F2} hole of an interpolated string.
 */
final class InterpolationHole extends Node
{
    public function __construct(
        public readonly Expr $expr,
        public readonly ?string $format,
        /** {value,8} right-aligns in 8 characters, {value,-8} left-aligns. */
        public readonly ?int $alignment = null,
    ) {
    }
}
