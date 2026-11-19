<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * { statements }
 */
final class Block extends Stmt
{
    public function __construct(
        /** @var list<Stmt> */
        public readonly array $statements,
    ) {
    }
}
