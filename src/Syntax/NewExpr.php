<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

use SharpPhp\Lexer\Token;

/**
 * $type is null for target-typed new().
 *
 * The node is at the "new" keyword; the "(" of the argument list has its own position, which editors
 * use to find the call the cursor is in (parameter info).
 */
final class NewExpr extends Expr
{
    /** Line of the "(" of the argument list; 0 when there is none (new T { ... }). */
    public int $argumentsLine = 0;

    /** Column of the "(" of the argument list. */
    public int $argumentsColumn = 0;

    public function __construct(
        public readonly ?TypeRef $type,
        /** @var list<Argument> */
        public readonly array $args,
        public readonly ?Initializer $initializer,
    ) {
    }

    /** Records where the argument list starts: the "(" token. */
    public function argumentsAt(Token $open): static
    {
        $this->argumentsLine = $open->line;
        $this->argumentsColumn = $open->column;

        return $this;
    }
}
