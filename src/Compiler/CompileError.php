<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use RuntimeException;

/**
 * Thrown by the lexer, parser and emitter; carries a single diagnostic.
 */
final class CompileError extends RuntimeException
{
    public function __construct(public readonly Diagnostic $diagnostic)
    {
        parent::__construct($diagnostic->format());
    }

    public static function at(string $code, string $message, ?SourceFile $file, int $line, int $column): self
    {
        return new self(new Diagnostic($code, $message, $file, $line, $column));
    }
}
