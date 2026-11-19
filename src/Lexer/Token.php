<?php

declare(strict_types=1);

namespace SharpPhp\Lexer;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        /** Raw text for identifiers/keywords/punctuation, decoded value for literals. */
        public readonly mixed $value,
        public readonly int $line,
        public readonly int $column,
        public readonly int $offset,
        public readonly int $length,
    ) {
    }

    public function is(string $punctOrKeyword): bool
    {
        return ($this->type === TokenType::Punct || $this->type === TokenType::Keyword)
            && $this->value === $punctOrKeyword;
    }

    public function isIdentifier(?string $name = null): bool
    {
        return $this->type === TokenType::Identifier && ($name === null || $this->value === $name);
    }

    /** True when $next starts right where this token ends (no whitespace in between). */
    public function touches(Token $next): bool
    {
        return $this->offset + $this->length === $next->offset;
    }

    public function describe(): string
    {
        return match ($this->type) {
            TokenType::EndOfFile => 'end of file',
            TokenType::String, TokenType::InterpolatedString => 'string literal',
            TokenType::Char => 'character literal',
            TokenType::Int, TokenType::Float => 'number',
            default => "'" . $this->value . "'",
        };
    }
}
