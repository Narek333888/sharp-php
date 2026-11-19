<?php

declare(strict_types=1);

namespace SharpPhp\Lexer;

enum TokenType
{
    case Identifier;
    case Keyword;
    case Int;
    case Float;
    case String;
    case Char;
    /** Value is a list of parts: string (text) or array{expr: string, line: int, column: int, offset: int, format: ?string}. */
    case InterpolatedString;
    case Punct;
    case EndOfFile;
}
