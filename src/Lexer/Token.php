<?php

declare(strict_types=1);

namespace SharpPhp\Lexer;

/**
 * A token of SharpPHP source: its kind, its text or literal value, and where it was written.
 */
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

    /**
     * The text of an identifier, keyword or punctuation token. (Literal tokens keep their decoded
     * value, which may be a number, in $value.)
     */
    public function text(): string
    {
        if (!is_string($this->value)) {
            throw new \LogicException("A {$this->type->name} token has no text");
        }

        return $this->value;
    }

    /**
     * The parts of an interpolated string token: literal text, or a hole with its expression's source.
     *
     * @return list<string|array{expr: string, line: int, column: int, offset: int, format: ?string, alignment: ?int}>
     */
    public function interpolationParts(): array
    {
        if ($this->type !== TokenType::InterpolatedString || !is_array($this->value)) {
            throw new \LogicException("A {$this->type->name} token has no interpolation parts");
        }
        /** @var list<string|array{expr: string, line: int, column: int, offset: int, format: ?string, alignment: ?int}> $parts built by Lexer::interpolated() */
        $parts = $this->value;

        return $parts;
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
            default => "'" . $this->text() . "'",
        };
    }
}
