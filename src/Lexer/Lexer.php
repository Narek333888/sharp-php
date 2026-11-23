<?php

declare(strict_types=1);

namespace SharpPhp\Lexer;

use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\SourceFile;

/**
 * Turns SharpPHP source text into tokens.
 *
 * Note: '>>' and '>>=' are never produced; the parser composes them from adjacent
 * '>' tokens so that nested generics like List<List<int>> work.
 */
final class Lexer
{
    public const KEYWORDS = [
        'abstract', 'as', 'base', 'bool', 'break', 'byte', 'case', 'catch', 'char', 'checked', 'class',
        'const', 'continue', 'decimal', 'default', 'delegate', 'do', 'double', 'else', 'enum', 'event',
        'explicit', 'extern', 'false', 'finally', 'fixed', 'float', 'for', 'foreach', 'goto', 'if',
        'implicit', 'in', 'int', 'interface', 'internal', 'is', 'lock', 'long', 'namespace', 'new', 'null',
        'object', 'operator', 'out', 'override', 'params', 'private', 'protected', 'public', 'readonly',
        'ref', 'return', 'sbyte', 'sealed', 'short', 'sizeof', 'stackalloc', 'static', 'string', 'struct',
        'switch', 'this', 'throw', 'true', 'try', 'typeof', 'uint', 'ulong', 'unchecked', 'unsafe',
        'ushort', 'using', 'virtual', 'void', 'volatile', 'while',
    ];

    private const PUNCTUATORS = [
        '<<=', '??=',
        '=>', '==', '!=', '<=', '>=', '&&', '||', '++', '--', '+=', '-=', '*=', '/=', '%=', '&=', '|=',
        '^=', '<<', '??', '?.', '::', '..',
        '{', '}', '(', ')', '[', ']', ';', ',', '.', ':', '?', '+', '-', '*', '/', '%', '&', '|', '^',
        '!', '~', '=', '<', '>',
    ];

    /** @var array<string, true> */
    private static array $keywordSet = [];

    private int $pos = 0;
    private int $line;
    private int $column;
    private readonly string $text;
    private readonly int $length;

    public function __construct(
        private readonly SourceFile $file,
        ?string $text = null,
        int $line = 1,
        int $column = 1,
        private readonly int $baseOffset = 0,
    ) {
        $this->text = $text ?? $file->text;
        $this->length = strlen($this->text);
        $this->line = $line;
        $this->column = $column;
        self::$keywordSet = self::$keywordSet ?: array_fill_keys(self::KEYWORDS, true);
    }

    /** @return list<Token> */
    public static function tokenize(SourceFile $file): array
    {
        return (new self($file))->all();
    }

    /** @return list<Token> */
    public function all(): array
    {
        $tokens = [];
        while (true) {
            $token = $this->next();
            $tokens[] = $token;
            if ($token->type === TokenType::EndOfFile) {
                return $tokens;
            }
        }
    }

    private function next(): Token
    {
        $this->skipTrivia();

        $start = $this->pos;
        $line = $this->line;
        $column = $this->column;

        if ($this->pos >= $this->length) {
            return $this->token(TokenType::EndOfFile, '', $start, $line, $column);
        }

        $c = $this->text[$this->pos];
        $n = $this->peekChar(1);

        // Interpolated strings: $"..."  $@"..."  @$"..."
        if ($c === '$' && $n === '"') {
            $this->advance(2);
            return $this->token(TokenType::InterpolatedString, $this->interpolated(false), $start, $line, $column);
        }
        if (($c === '$' && $n === '@' || $c === '@' && $n === '$') && $this->peekChar(2) === '"') {
            $this->advance(3);
            return $this->token(TokenType::InterpolatedString, $this->interpolated(true), $start, $line, $column);
        }

        // Verbatim strings: @"..."
        if ($c === '@' && $n === '"') {
            $this->advance(2);
            return $this->token(TokenType::String, $this->verbatimString(), $start, $line, $column);
        }

        // Verbatim identifiers: @class
        if ($c === '@' && $n !== '' && self::isIdentStart($n)) {
            $this->advance(1);
            $name = $this->identifierText();
            return $this->token(TokenType::Identifier, $name, $start, $line, $column);
        }

        if (self::isIdentStart($c)) {
            $name = $this->identifierText();
            $type = isset(self::$keywordSet[$name]) ? TokenType::Keyword : TokenType::Identifier;
            return $this->token($type, $name, $start, $line, $column);
        }

        if (ctype_digit($c) || ($c === '.' && ctype_digit($n))) {
            return $this->number($start, $line, $column);
        }

        if ($c === '"') {
            $this->advance(1);
            return $this->token(TokenType::String, $this->regularString(), $start, $line, $column);
        }

        if ($c === "'") {
            $this->advance(1);
            return $this->token(TokenType::Char, $this->charLiteral(), $start, $line, $column);
        }

        foreach (self::PUNCTUATORS as $punct) {
            if (substr_compare($this->text, $punct, $this->pos, strlen($punct)) === 0) {
                // "a ?.5 : b" is a conditional followed by a number, not '?.'.
                if ($punct === '?.' && ctype_digit($this->peekChar(2))) {
                    continue;
                }
                $this->advance(strlen($punct));
                return $this->token(TokenType::Punct, $punct, $start, $line, $column);
            }
        }

        throw $this->error("Unexpected character '$c'", $line, $column);
    }

    private function skipTrivia(): void
    {
        while ($this->pos < $this->length) {
            $c = $this->text[$this->pos];

            if ($c === "\n" || $c === "\r" || $c === ' ' || $c === "\t" || $c === "\f" || $c === "\v") {
                $this->advance(1);
                continue;
            }

            if ($c === '/' && $this->peekChar(1) === '/') {
                while ($this->pos < $this->length && $this->text[$this->pos] !== "\n") {
                    $this->advance(1);
                }
                continue;
            }

            if ($c === '/' && $this->peekChar(1) === '*') {
                $line = $this->line;
                $column = $this->column;
                $end = strpos($this->text, '*/', $this->pos + 2);
                if ($end === false) {
                    throw $this->error('End-of-file found, \'*/\' expected', $line, $column);
                }
                $this->advance($end + 2 - $this->pos);
                continue;
            }

            // Preprocessor directives (#region, #pragma, ...) are ignored.
            if ($c === '#' && $this->atLineStart()) {
                while ($this->pos < $this->length && $this->text[$this->pos] !== "\n") {
                    $this->advance(1);
                }
                continue;
            }

            return;
        }
    }

    private function atLineStart(): bool
    {
        for ($i = $this->pos - 1; $i >= 0; $i--) {
            $c = $this->text[$i];
            if ($c === "\n") {
                return true;
            }
            if ($c !== ' ' && $c !== "\t") {
                return false;
            }
        }

        return true;
    }

    private function identifierText(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->length && self::isIdentPart($this->text[$this->pos])) {
            $this->advance(1);
        }

        return substr($this->text, $start, $this->pos - $start);
    }

    private function number(int $start, int $line, int $column): Token
    {
        $text = $this->text;
        $isFloat = false;

        if ($text[$this->pos] === '0' && in_array(strtolower($this->peekChar(1)), ['x', 'b'], true)) {
            $base = strtolower($this->peekChar(1)) === 'x' ? 16 : 2;
            $this->advance(2);
            $digitsStart = $this->pos;
            while ($this->pos < $this->length && (ctype_xdigit($text[$this->pos]) || $text[$this->pos] === '_')) {
                $this->advance(1);
            }
            $digits = str_replace('_', '', substr($text, $digitsStart, $this->pos - $digitsStart));
            $this->integerSuffix();
            $value = $base === 16 ? hexdec($digits) : bindec($digits);

            return $this->token(is_int($value) ? TokenType::Int : TokenType::Float, $value, $start, $line, $column);
        }

        $this->digits();
        if ($this->peekChar(0) === '.' && ctype_digit($this->peekChar(1))) {
            $isFloat = true;
            $this->advance(1);
            $this->digits();
        }
        if (in_array($this->peekChar(0), ['e', 'E'], true)
            && (ctype_digit($this->peekChar(1)) || (in_array($this->peekChar(1), ['+', '-'], true) && ctype_digit($this->peekChar(2))))) {
            $isFloat = true;
            $this->advance(2);
            $this->digits();
        }

        $raw = str_replace('_', '', substr($text, $start, $this->pos - $start));

        if (in_array($this->peekChar(0), ['f', 'F', 'd', 'D', 'm', 'M'], true)) {
            $this->advance(1);
            $isFloat = true;
        } else {
            $this->integerSuffix();
        }

        if ($isFloat) {
            return $this->token(TokenType::Float, (float) $raw, $start, $line, $column);
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return $value === false
            ? $this->token(TokenType::Float, (float) $raw, $start, $line, $column)
            : $this->token(TokenType::Int, $value, $start, $line, $column);
    }

    private function digits(): void
    {
        while ($this->pos < $this->length && (ctype_digit($this->text[$this->pos]) || $this->text[$this->pos] === '_')) {
            $this->advance(1);
        }
    }

    private function integerSuffix(): void
    {
        while (in_array($this->peekChar(0), ['u', 'U', 'l', 'L'], true)) {
            $this->advance(1);
        }
    }

    private function regularString(): string
    {
        $line = $this->line;
        $column = $this->column - 1;
        $out = '';
        while (true) {
            if ($this->pos >= $this->length || $this->text[$this->pos] === "\n") {
                throw $this->error('Newline in constant', $line, $column);
            }
            $c = $this->text[$this->pos];
            if ($c === '"') {
                $this->advance(1);
                return $out;
            }
            if ($c === '\\') {
                $out .= $this->escape();
                continue;
            }
            $out .= $c;
            $this->advance(1);
        }
    }

    private function verbatimString(): string
    {
        $line = $this->line;
        $column = $this->column - 2;
        $out = '';
        while (true) {
            if ($this->pos >= $this->length) {
                throw $this->error('Unterminated string literal', $line, $column);
            }
            $c = $this->text[$this->pos];
            if ($c === '"') {
                if ($this->peekChar(1) === '"') {
                    $out .= '"';
                    $this->advance(2);
                    continue;
                }
                $this->advance(1);
                return $out;
            }
            $out .= $c;
            $this->advance(1);
        }
    }

    private function charLiteral(): string
    {
        $line = $this->line;
        $column = $this->column - 1;
        if ($this->peekChar(0) === '\\') {
            $value = $this->escape();
        } else {
            $value = mb_substr(substr($this->text, $this->pos, 4), 0, 1);
            if ($value === '' || $value === "'" || $value === "\n") {
                throw $this->error('Empty character literal', $line, $column);
            }
            $this->advance(strlen($value));
        }
        if ($this->peekChar(0) !== "'") {
            throw $this->error('Too many characters in character literal', $line, $column);
        }
        $this->advance(1);

        return $value;
    }

    private function escape(): string
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance(1); // backslash
        $c = $this->peekChar(0);
        $this->advance(1);

        $simple = [
            'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", '\\' => '\\', '"' => '"', "'" => "'",
            'a' => "\x07", 'b' => "\x08", 'f' => "\f", 'v' => "\v", 'e' => "\x1B",
        ];
        if (isset($simple[$c])) {
            return $simple[$c];
        }

        if ($c === 'u' || $c === 'x' || $c === 'U') {
            $max = $c === 'U' ? 8 : 4;
            $hex = '';
            while (strlen($hex) < $max && ctype_xdigit($this->peekChar(0))) {
                $hex .= $this->peekChar(0);
                $this->advance(1);
            }
            if ($hex === '' || ($c === 'u' && strlen($hex) !== 4)) {
                throw $this->error('Unrecognized escape sequence', $line, $column);
            }

            return mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
        }

        throw $this->error("Unrecognized escape sequence '\\$c'", $line, $column);
    }

    /**
     * @return list<string|array{expr: string, line: int, column: int, offset: int, format: ?string, alignment: ?int}>
     */
    private function interpolated(bool $verbatim): array
    {
        $line = $this->line;
        $column = $this->column - ($verbatim ? 3 : 2);
        $parts = [];
        $text = '';

        while (true) {
            if ($this->pos >= $this->length || (!$verbatim && $this->text[$this->pos] === "\n")) {
                throw $this->error('Unterminated interpolated string', $line, $column);
            }
            $c = $this->text[$this->pos];

            if ($c === '"') {
                if ($verbatim && $this->peekChar(1) === '"') {
                    $text .= '"';
                    $this->advance(2);
                    continue;
                }
                $this->advance(1);
                break;
            }
            if ($c === '{' && $this->peekChar(1) === '{') {
                $text .= '{';
                $this->advance(2);
                continue;
            }
            if ($c === '}' && $this->peekChar(1) === '}') {
                $text .= '}';
                $this->advance(2);
                continue;
            }
            if ($c === '}') {
                throw $this->error("A '}' character must be escaped (by doubling) in an interpolated string", $this->line, $this->column);
            }
            if ($c === '\\' && !$verbatim) {
                $text .= $this->escape();
                continue;
            }
            if ($c === '{') {
                if ($text !== '') {
                    $parts[] = $text;
                    $text = '';
                }
                $parts[] = $this->interpolationHole($verbatim);
                continue;
            }
            $text .= $c;
            $this->advance(1);
        }

        if ($text !== '' || $parts === []) {
            $parts[] = $text;
        }

        return $parts;
    }

    /**
     * One {expression[,alignment][:format]} of an interpolated string ($"{name,-10}|{price,8:F2}"). As in C#, the escape sequences of a regular
     * (not verbatim) string apply to the format too: $"{span:hh\\:mm}" formats with hh\:mm.
     *
     * @return array{expr: string, line: int, column: int, offset: int, format: ?string, alignment: ?int}
     */
    private function interpolationHole(bool $verbatim): array
    {
        $holeLine = $this->line;
        $holeColumn = $this->column;
        $this->advance(1); // {
        $exprLine = $this->line;
        $exprColumn = $this->column;
        $exprStart = $this->pos;
        $depth = 0;
        $format = null;
        $alignment = null;
        $exprEnd = null;

        while (true) {
            if ($this->pos >= $this->length) {
                throw $this->error('Unterminated interpolation hole', $holeLine, $holeColumn);
            }
            $c = $this->text[$this->pos];

            if ($c === '"' || $c === "'" || ($c === '@' && $this->peekChar(1) === '"') || ($c === '$' && in_array($this->peekChar(1), ['"', '@'], true))) {
                $this->next(); // skip nested literal as a whole token
                continue;
            }
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif (($c === ')' || $c === ']') && $depth > 0) {
                $depth--;
            } elseif ($c === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0 && $exprEnd === null
                && preg_match('/\G,\s*([+-]?\s*\d+)\s*(?=[:}])/', $this->text, $m, 0, $this->pos) === 1) {
                // The alignment: a number after a comma (a comma in "Dictionary<int, string>" is not one).
                $exprEnd = $this->pos;
                $alignment = (int) str_replace(' ', '', $m[1]);
                $this->advance(strlen($m[0]));
                continue;
            } elseif ($c === ':' && $depth === 0 && $this->peekChar(1) !== ':') {
                $exprEnd ??= $this->pos;
                $this->advance(1);
                $format = '';
                while ($this->pos < $this->length && $this->text[$this->pos] !== '}') {
                    if ($this->text[$this->pos] === '\\' && !$verbatim) {
                        $format .= $this->escape();
                        continue;
                    }
                    $format .= $this->text[$this->pos];
                    $this->advance(1);
                }
                break;
            }
            $this->advance(1);
        }

        $expr = substr($this->text, $exprStart, ($exprEnd ?? $this->pos) - $exprStart);
        $this->advance(1); // }

        if (trim($expr) === '') {
            throw $this->error('Empty interpolation hole', $holeLine, $holeColumn);
        }

        return [
            'expr' => $expr,
            'line' => $exprLine,
            'column' => $exprColumn,
            'offset' => $this->baseOffset + $exprStart,
            'format' => $format,
            'alignment' => $alignment,
        ];
    }

    private function peekChar(int $ahead): string
    {
        return $this->text[$this->pos + $ahead] ?? '';
    }

    private function advance(int $count): void
    {
        for ($i = 0; $i < $count && $this->pos < $this->length; $i++) {
            $c = $this->text[$this->pos++];
            if ($c === "\n") {
                $this->line++;
                $this->column = 1;
            } elseif ((ord($c) & 0xC0) !== 0x80) {
                $this->column++;
            }
        }
    }

    private function token(TokenType $type, mixed $value, int $start, int $line, int $column): Token
    {
        return new Token($type, $value, $line, $column, $this->baseOffset + $start, $this->pos - $start);
    }

    private function error(string $message, int $line, int $column): CompileError
    {
        return CompileError::at('SP1001', $message, $this->file, $line, $column);
    }

    private static function isIdentStart(string $c): bool
    {
        return $c === '_' || ctype_alpha($c) || ord($c) >= 0x80;
    }

    private static function isIdentPart(string $c): bool
    {
        return $c === '_' || ctype_alnum($c) || ord($c) >= 0x80;
    }
}
