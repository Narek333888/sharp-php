<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * switch (subject) { case ...: ... }
 */
final class SwitchStmt extends Stmt
{
    public function __construct(
        public readonly Expr $subject,
        /** @var list<SwitchSection> */
        public readonly array $sections,
    ) {
    }
}
