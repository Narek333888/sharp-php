<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * One arm of a switch expression: pattern when condition => value
 */
final class SwitchArm extends Node
{
    public function __construct(
        public readonly Pattern $pattern,
        public readonly ?Expr $when,
        public readonly Expr $result,
    ) {
    }
}
