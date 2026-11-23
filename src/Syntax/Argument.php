<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * modifier: ref | out | in
 */
final class Argument extends Node
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $modifier,
        public readonly Expr $value,
        /** The argument as written, for [CallerArgumentExpression]. */
        public readonly ?string $text = null,
    ) {
    }
}
