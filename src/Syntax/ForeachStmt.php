<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class ForeachStmt extends Stmt
{
    public function __construct(
        public readonly ?TypeRef $type,
        public readonly ?string $variable,
        /** @var list<string> */
        public readonly array $deconstruction,
        public readonly Expr $collection,
        public readonly Stmt $body,
    ) {
    }
}
