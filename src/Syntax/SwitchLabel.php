<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * case pattern [when expr]:  — $pattern is null for "default:".
 */
final class SwitchLabel extends Node
{
    public function __construct(
        public readonly ?Pattern $pattern,
        public readonly ?Expr $when,
    ) {
    }
}
