<?php

declare(strict_types=1);

namespace SharpPhp\Lsp;

use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\Compiler;
use SharpPhp\Compiler\SemanticModel;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Parser\Parser;
use SharpPhp\Semantics\SymbolTable;

/**
 * Analysis of a document while it is being typed, for completion and parameter info.
 *
 * Code being typed often does not parse ("user." has no member name yet, "Console.WriteLine(" no
 * closing parenthesis), so a copy of the document gets a placeholder name at the cursor and, if
 * needed, the end of the statement. The compiler analyzes that copy and records in a SemanticModel
 * what it learned: the placeholder's receiver, the names in scope there, the calls around it.
 *
 * An Analysis is the model, the project's symbols, and the document's normalized path.
 *
 * @phpstan-type Analysis array{0: SemanticModel, 1: SymbolTable, 2: string}
 */
final class DraftAnalyzer
{
    /** Endings tried after the placeholder until the copy parses: "Console.WriteLine(user." needs ");". */
    private const ENDINGS = ['', ';', ');', '));', ')));', ';}', ';}}'];

    public function __construct(private readonly Workspace $workspace)
    {
    }

    /**
     * The document as it is, when it parses (a call whose closing parenthesis the editor already
     * typed: "Console.WriteLine()").
     *
     * @return Analysis|null
     */
    public function asIs(string $path): ?array
    {
        $realPath = Workspace::normalize($path);

        return $this->analyze($realPath, $this->workspace->text($path) ?? '');
    }

    /**
     * A copy of the document with the placeholder name on line $line (0-based), between $head and
     * $tail, and the first ending that makes it parse.
     *
     * @param list<string> $lines the document's lines
     * @return Analysis|null null when no ending makes the copy parse
     */
    public function withPlaceholder(string $path, array $lines, int $line, string $head, string $tail): ?array
    {
        $realPath = Workspace::normalize($path);
        foreach (self::ENDINGS as $ending) {
            $copy = $lines;
            $copy[$line] = $head . SemanticModel::PLACEHOLDER . $ending . $tail;
            $analysis = $this->analyze($realPath, implode("\n", $copy));
            if ($analysis !== null) {
                return $analysis;
            }
        }

        return null;
    }

    /**
     * Analyzes the project with $text as the document at $realPath; null when that text does not parse.
     *
     * @return Analysis|null
     */
    private function analyze(string $realPath, string $text): ?array
    {
        try {
            Parser::parseFile(new SourceFile($realPath, $text));
        } catch (CompileError) {
            return null;
        }
        $model = new SemanticModel();
        $result = (new Compiler())->analyze($this->workspace->sources([$realPath => $text]), $model);

        return [$model, $result['symbols'], $realPath];
    }
}
