<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * Local variable declaration; $type is null for "var".
 */
final class LocalDecl extends Stmt
{
    public function __construct(
        public readonly ?TypeRef $type,
        /** @var list<VarDeclarator> */
        public readonly array $declarators,
        public readonly bool $isConst,
    ) {
    }
}
