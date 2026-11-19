<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * Base class of all syntax nodes. Every node knows where it came from.
 */
abstract class Node
{
    public int $line = 0;
    public int $column = 0;

    /**
     * Copies the source position from a token or another node.
     */
    public function at(\SharpPhp\Lexer\Token|Node $origin): static
    {
        $this->line = $origin->line;
        $this->column = $origin->column;

        return $this;
    }
}
