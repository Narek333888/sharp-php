<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * try { ... } catch ... finally { ... }
 */
final class TryStmt extends Stmt
{
    public function __construct(
        public readonly Block $body,
        /** @var list<CatchClause> */
        public readonly array $catches,
        public readonly ?Block $finally,
    ) {
    }
}
