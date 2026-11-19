<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * for (init; condition; iterators) ...
 */
final class ForStmt extends Stmt
{
    public function __construct(
        public readonly ?LocalDecl $declaration,
        /** @var list<Expr> */
        public readonly array $initializers,
        public readonly ?Expr $condition,
        /** @var list<Expr> */
        public readonly array $iterators,
        public readonly Stmt $body,
    ) {
    }
}
