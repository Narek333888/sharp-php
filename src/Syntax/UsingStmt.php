<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * using (var r = Open()) { ... }, using (expression) statement, or the declaration form
 * "using var r = Open();", whose body is the rest of the enclosing block.
 * The resources are disposed when the body is left, in reverse order, also by an exception.
 */
final class UsingStmt extends Stmt
{
    public function __construct(
        public readonly ?LocalDecl $declaration,
        public readonly ?Expr $expression,
        /** Null for a using declaration until the parser gives it the rest of the block. */
        public readonly ?Stmt $body,
        public readonly bool $isDeclaration,
    ) {
    }
}
