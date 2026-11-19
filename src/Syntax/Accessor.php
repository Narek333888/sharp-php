<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * get / set / init accessor. Auto accessor when both bodies are null.
 */
final class Accessor extends Node
{
    public function __construct(
        public readonly string $kind,
        /** @var list<string> */
        public readonly array $modifiers,
        public readonly ?Block $body,
        public readonly ?Expr $exprBody,
    ) {
    }
}
