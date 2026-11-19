<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * Case labels of a switch statement and the statements they share.
 */
final class SwitchSection extends Node
{
    public function __construct(
        /** @var list<SwitchLabel> */
        public readonly array $labels,
        /** @var list<Stmt> */
        public readonly array $statements,
    ) {
    }
}
