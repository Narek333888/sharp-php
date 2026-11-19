<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * Field or constant declaration (may declare several names).
 */
final class FieldDecl extends Node
{
    public function __construct(
        /** @var list<string> */
        public readonly array $modifiers,
        public readonly TypeRef $type,
        /** @var list<VarDeclarator> */
        public readonly array $declarators,
        public readonly bool $isConst,
    ) {
    }
}
