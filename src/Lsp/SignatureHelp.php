<?php

declare(strict_types=1);

namespace SharpPhp\Lsp;

use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\SemanticModel;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Lexer\Lexer;
use SharpPhp\Lexer\TokenType;
use SharpPhp\Semantics\ParameterSymbol;

/**
 * Parameter info: while the arguments of a call are typed, the signature of the method or
 * constructor that is called, with the parameter of the current argument highlighted.
 *
 *   new Random(|              Random(int Seed)
 *   text.PadLeft(5, |         string string.PadLeft(int totalWidth, string paddingChar)
 *   Person.Create(name: |     Person Person.Create(string name)
 *
 * The call is found in two steps. The text before the cursor gives the "(" of the argument lists
 * that are still open there; it is read with SharpPHP's lexer, so parentheses in strings and
 * comments do not count. The compiler tells what is called at such a "(": it records every call at
 * the position of its "(" (SemanticModel::callAt). Code being typed is analyzed as completion does
 * it (DraftAnalyzer): as it is when it parses, otherwise with a placeholder argument at the cursor.
 *
 * @phpstan-import-type Call from SemanticModel
 * @phpstan-import-type Analysis from DraftAnalyzer
 */
final class SignatureHelp
{
    private readonly DraftAnalyzer $drafts;

    public function __construct(private readonly Workspace $workspace)
    {
        $this->drafts = new DraftAnalyzer($workspace);
    }

    /**
     * @param int $line 0-based, as in LSP
     * @param int $character 0-based, as in LSP
     * @return array<string, mixed>|null LSP SignatureHelp; null when the cursor is not in the arguments of a call
     */
    public function help(string $path, int $line, int $character): ?array
    {
        $lines = explode("\n", $this->workspace->text($path) ?? '');
        $lineText = rtrim($lines[$line] ?? '', "\r");
        $before = mb_substr($lineText, 0, $character);
        $after = mb_substr($lineText, $character);

        $parentheses = self::openParentheses($path, implode("\n", [...array_slice($lines, 0, $line), $before]));
        if ($parentheses === []) {
            return null;
        }
        $call = $this->findCall($path, $lines, $line, $before, $after, $parentheses);
        if ($call === null) {
            return null;
        }
        // Between a comma and the next argument ("f(1,| 2)") the cursor is at that argument already.
        $column = $character + 1 + (mb_strlen($after) - mb_strlen(ltrim($after, " \t")));

        return self::signatureHelp($call, self::activeParameter($call, $line + 1, $column));
    }

    /**
     * The "(" still open at the end of $text, innermost first: the argument lists the cursor may be
     * in. They stop at an open "{" or "[", as the cursor is then in a lambda body or an index, not in
     * the arguments of the call around it. Empty when $text ends inside a string or a comment.
     *
     * @return list<array{0: int, 1: int}> line and column (1-based) of each "("
     */
    private static function openParentheses(string $path, string $text): array
    {
        try {
            $tokens = Lexer::tokenize(new SourceFile($path, $text));
        } catch (CompileError) {
            return [];
        }
        $open = [];
        foreach ($tokens as $token) {
            if ($token->type !== TokenType::Punct) {
                continue;
            }
            $punct = $token->text();
            if ($punct === '(' || $punct === '[' || $punct === '{') {
                $open[] = [$punct, $token->line, $token->column];
            } elseif ($punct === ')' || $punct === ']' || $punct === '}') {
                array_pop($open);
            }
        }
        $parentheses = [];
        foreach (array_reverse($open) as [$bracket, $line, $column]) {
            if ($bracket !== '(') {
                break;
            }
            $parentheses[] = [$line, $column];
        }

        return $parentheses;
    }

    /**
     * The call whose arguments the cursor is in: the innermost open "(" that starts a call. In
     * "Math.Max((a + |" that is Max's, as "(a + " only groups an expression.
     *
     * @param list<string> $lines the document's lines
     * @param list<array{0: int, 1: int}> $parentheses from openParentheses()
     * @return Call|null
     */
    private function findCall(string $path, array $lines, int $line, string $before, string $after, array $parentheses): ?array
    {
        foreach ($this->analyses($path, $lines, $line, $before, $after) as [$model, , $realPath]) {
            foreach ($parentheses as [$parenLine, $parenColumn]) {
                $call = $model->callAt($realPath, $parenLine, $parenColumn);
                if ($call !== null) {
                    return $call;
                }
            }
        }

        return null;
    }

    /**
     * Analyses to look the call up in, made only when needed: the document as it is (the editor
     * often closed the parenthesis already), then a copy with a placeholder argument at the cursor,
     * in place of the word being typed.
     *
     * @param list<string> $lines
     * @return \Generator<int, Analysis>
     */
    private function analyses(string $path, array $lines, int $line, string $before, string $after): \Generator
    {
        $asIs = $this->drafts->asIs($path);
        if ($asIs !== null) {
            yield $asIs;
        }
        $word = preg_match('/[A-Za-z_][A-Za-z0-9_]*$/u', $before, $m) === 1 ? $m[0] : '';
        $head = mb_substr($before, 0, mb_strlen($before) - mb_strlen($word));
        $tail = preg_replace('/^[A-Za-z0-9_]+/u', '', $after) ?? $after;
        $draft = $this->drafts->withPlaceholder($path, $lines, $line, $head, $tail);
        if ($draft !== null) {
            yield $draft;
        }
    }

    /**
     * The index of the parameter the argument at the cursor goes to: the last argument starting at
     * or before the cursor, matched by name when it is a named argument ("seed: 5"). Arguments past
     * a "params" parameter all go to it.
     *
     * @param Call $call
     * @param int $line 1-based
     * @param int $column 1-based
     */
    private static function activeParameter(array $call, int $line, int $column): int
    {
        $index = 0;
        $name = null;
        foreach ($call['arguments'] as $i => $argument) {
            if ($argument['line'] < $line || ($argument['line'] === $line && $argument['column'] <= $column)) {
                $index = $i;
                $name = $argument['name'];
            }
        }
        $params = $call['params'];
        if ($name !== null) {
            foreach ($params as $i => $param) {
                if ($param->name === $name) {
                    return $i;
                }
            }
        }
        $last = array_key_last($params);
        if ($last !== null && $index > $last && $params[$last]->modifier === 'params') {
            return $last;
        }

        return $index;
    }

    /**
     * The LSP answer: one signature, as C# writes it ("string string.PadLeft(int totalWidth, ...)"),
     * whose parameters are given as their [start, end) offsets in that label.
     *
     * @param Call $call
     * @return array<string, mixed> LSP SignatureHelp
     */
    private static function signatureHelp(array $call, int $active): array
    {
        $label = ($call['returns'] !== null ? $call['returns']->describe() . ' ' : '') . $call['name'] . '(';
        $parameters = [];
        foreach ($call['params'] as $i => $param) {
            if ($i > 0) {
                $label .= ', ';
            }
            $start = self::utf16Length($label);
            $label .= self::describe($param);
            $parameters[] = ['label' => [$start, self::utf16Length($label)]];
        }
        $label .= ')';

        return [
            'signatures' => [['label' => $label, 'parameters' => $parameters, 'activeParameter' => $active]],
            'activeSignature' => 0,
            'activeParameter' => $active,
        ];
    }

    /** "int count", "out int result", "int count = ..." for an optional one, "params object[] args". */
    private static function describe(ParameterSymbol $param): string
    {
        if ($param->modifier === 'params') {
            // A PHP variadic (...$args) has the element's type; C# writes the array, and no default.
            $type = $param->type->kind === 'array' ? $param->type->describe() : $param->type->describe() . '[]';

            return "params $type {$param->name}";
        }

        return ($param->modifier !== null ? $param->modifier . ' ' : '') . $param->type->describe() . ' ' . $param->name
            . ($param->optional ? ' = ...' : '');
    }

    /** Length in UTF-16 code units, the unit of LSP offsets. */
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
