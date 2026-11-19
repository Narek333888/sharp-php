<?php

declare(strict_types=1);

namespace SharpPhp\Lsp;

use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Compiler\Compiler;
use SharpPhp\Compiler\Diagnostic;
use SharpPhp\Compiler\SemanticModel;
use SharpPhp\Semantics\ParameterSymbol;

/**
 * Editor features on top of the compiler: diagnostics, hover, go to definition and completion.
 * Positions in and out are LSP positions (0-based line and character).
 *
 * @phpstan-import-type Reference from SemanticModel
 */
final class LanguageService
{
    /** @var array{key: string, model: SemanticModel}|null analysis of the current sources, reused until they change */
    private ?array $analysis = null;

    public function __construct(private readonly Workspace $workspace)
    {
    }

    /**
     * Compile errors of every file, exactly as "sharp build" reports them.
     *
     * @return array<string, list<array<string, mixed>>> path => LSP Diagnostics
     */
    public function diagnostics(): array
    {
        $sources = $this->workspace->sources();
        $byFile = [];
        foreach ($sources as $file) {
            $byFile[$file->path] = [];
        }
        try {
            (new Compiler())->compile($sources);
        } catch (CompilationFailed $e) {
            foreach ($e->diagnostics as $diagnostic) {
                $path = $diagnostic->file?->path;
                if ($path !== null) {
                    $byFile[$path][] = self::lspDiagnostic($diagnostic);
                }
            }
        }

        return $byFile;
    }

    /** @return array<string, mixed>|null LSP Hover */
    public function hover(string $path, int $line, int $character): ?array
    {
        $ref = $this->model()->referenceAt(Workspace::normalize($path), $line + 1, $character + 1);
        if ($ref === null) {
            return null;
        }

        return [
            'contents' => ['kind' => 'markdown', 'value' => "```csharp\n" . self::describe($ref) . "\n```"],
            'range' => self::range($ref['line'], $ref['column'], $ref['length']),
        ];
    }

    /** @return array<string, mixed>|null LSP Location */
    public function definition(string $path, int $line, int $character): ?array
    {
        $ref = $this->model()->referenceAt(Workspace::normalize($path), $line + 1, $character + 1);
        $declaration = $ref['declaration'] ?? null;
        if ($declaration === null) {
            return null;
        }
        [$file, $declLine, $declColumn] = $declaration;

        return ['uri' => Workspace::pathToUri($file), 'range' => self::range($declLine, $declColumn, 0)];
    }

    /** @return list<array<string, mixed>> LSP CompletionItems */
    public function completion(string $path, int $line, int $character): array
    {
        return (new Completion($this->workspace))->complete($path, $line, $character);
    }

    private function model(): SemanticModel
    {
        $sources = $this->workspace->sources();
        $key = sha1(implode("\0", array_map(static fn ($f): string => $f->path . "\0" . $f->text, $sources)));
        if ($this->analysis === null || $this->analysis['key'] !== $key) {
            $model = new SemanticModel();
            (new Compiler())->analyze($sources, $model);
            $this->analysis = ['key' => $key, 'model' => $model];
        }

        return $this->analysis['model'];
    }

    /** @param Reference $ref */
    private static function describe(array $ref): string
    {
        $member = $ref['member'];
        if ($member !== null) {
            $owner = $member->owner->shortName();

            return match ($member->kind) {
                'method' => '(method) ' . $member->type->describe() . " $owner.{$member->name}(" . implode(', ', array_map(
                    static fn (ParameterSymbol $p): string => ($p->modifier !== null ? $p->modifier . ' ' : '') . $p->type->describe() . ' ' . $p->name . ($p->optional ? ' = ...' : ''),
                    $member->params,
                )) . ')',
                'field' => '(field) ' . $member->type->describe() . " $owner.{$member->name}",
                'const' => '(constant) ' . $member->type->describe() . " $owner.{$member->name}",
                'case' => "(enum member) $owner.{$member->name}",
                default => '(property) ' . $member->type->describe() . " $owner.{$member->name}",
            };
        }
        $symbol = $ref['symbol'];
        if ($symbol !== null) {
            $kind = match (true) {
                $symbol->isInterface() => 'interface',
                $symbol->isEnum() => 'enum',
                $symbol->isRecord => 'record',
                $symbol->isStruct => 'struct',
                default => 'class',
            };

            return $kind . ' ' . $ref['type']->describe();
        }

        return match ($ref['kind']) {
            'local' => '(local variable) ' . $ref['type']->describe() . ' ' . $ref['name'],
            'namespace' => 'namespace ' . $ref['name'],
            default => $ref['type']->describe() . ' ' . $ref['name'],
        };
    }

    /** @return array<string, mixed> */
    private static function lspDiagnostic(Diagnostic $diagnostic): array
    {
        $line = max(1, $diagnostic->line);
        $column = max(1, $diagnostic->column);
        $text = $diagnostic->file?->line($line) ?? '';
        // Underline the word (or the character) the error points at.
        $rest = mb_substr($text, $column - 1);
        $length = preg_match('/^(?:[A-Za-z0-9_]+|"(?:[^"\\\\]|\\\\.)*"|\S)/u', $rest, $m) === 1 ? mb_strlen($m[0]) : 1;

        return [
            'range' => self::range($line, $column, $length),
            'severity' => 1,
            'code' => $diagnostic->code,
            'source' => 'sharpphp',
            'message' => $diagnostic->message,
        ];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} from a 1-based position */
    private static function range(int $line, int $column, int $length): array
    {
        return [
            'start' => ['line' => $line - 1, 'character' => $column - 1],
            'end' => ['line' => $line - 1, 'character' => $column - 1 + $length],
        ];
    }
}
