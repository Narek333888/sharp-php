<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Compiler\SourceFile;
use SharpPhp\Lexer\Lexer;
use SharpPhp\Lexer\Token;
use SharpPhp\Lexer\TokenType;

final class LexerTest extends TestCase
{
    /** @return list<array{0: string, 1: mixed}> */
    private function lex(string $code): array
    {
        return array_map(
            static fn (Token $t): array => [$t->type->name, $t->value],
            array_slice(Lexer::tokenize(new SourceFile('t.sphp', $code)), 0, -1),
        );
    }

    public function testNumbers(): void
    {
        $this->assertSame(
            [['Int', 42], ['Int', 31], ['Int', 5], ['Int', 1000000], ['Float', 1.5], ['Float', 2.0], ['Float', 1.0e3], ['Int', 7]],
            $this->lex('42 0x1F 0b101 1_000_000 1.5 2d 1e3 7L'),
        );
    }

    public function testMemberAccessOnIntegerIsNotAFloat(): void
    {
        $this->assertSame([['Int', 1], ['Punct', '.'], ['Identifier', 'ToString']], $this->lex('1.ToString'));
    }

    public function testStringsAndEscapes(): void
    {
        $this->assertSame(
            [['String', "a\tb\"c"], ['String', 'C:\path\"q"'], ['Char', "\n"], ['Char', 'é']],
            $this->lex('"a\tb\"c" @"C:\path\""q""" \'\n\' \'é\''),
        );
    }

    public function testKeywordsAndVerbatimIdentifiers(): void
    {
        $this->assertSame([['Keyword', 'class'], ['Identifier', 'class'], ['Identifier', 'var']], $this->lex('class @class var'));
    }

    public function testNestedGenericsDoNotProduceShiftTokens(): void
    {
        $this->assertSame(
            [['Identifier', 'List'], ['Punct', '<'], ['Identifier', 'List'], ['Punct', '<'], ['Keyword', 'int'], ['Punct', '>'], ['Punct', '>']],
            $this->lex('List<List<int>>'),
        );
    }

    public function testInterpolatedStringParts(): void
    {
        $tokens = Lexer::tokenize(new SourceFile('t.sphp', '$"Hi {name}! {{x}} {value:F2} {(a ? "y" : "n")}"'));
        $this->assertSame(TokenType::InterpolatedString, $tokens[0]->type);
        $parts = $tokens[0]->value;
        $this->assertSame('Hi ', $parts[0]);
        $this->assertSame('name', $parts[1]['expr']);
        $this->assertSame('! {x} ', $parts[2]);
        $this->assertSame(['value', 'F2'], [$parts[3]['expr'], $parts[3]['format']]);
        $this->assertSame('(a ? "y" : "n")', $parts[5]['expr']);
    }

    public function testCommentsAndDirectivesAreSkipped(): void
    {
        $this->assertSame([['Identifier', 'a'], ['Identifier', 'b']], $this->lex("#region x\na // c\n/* d */ b\n#endregion"));
    }

    public function testPositions(): void
    {
        $tokens = Lexer::tokenize(new SourceFile('t.sphp', "x\n  yé z"));
        $this->assertSame([[1, 1], [2, 3], [2, 6]], array_map(static fn (Token $t): array => [$t->line, $t->column], array_slice($tokens, 0, 3)));
    }
}
