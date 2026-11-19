<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * subject switch { pattern => value, ... }
 */
final class SwitchExpr extends Expr
{
    public function __construct(
        public readonly Expr $subject,
        /** @var list<SwitchArm> */
        public readonly array $arms,
    ) {
    }
}
