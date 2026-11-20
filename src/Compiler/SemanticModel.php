<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\ResolutionContext;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\Node;

/**
 * What the compiler learned about the source while compiling it in analysis mode (Compiler::analyze):
 * the meaning of names and member accesses, the names visible at a point, and the calls. Editors use
 * it (through the language server) for hover, go to definition, completion and parameter info.
 *
 * Positions are 1-based line and column, as in diagnostics.
 *
 * @phpstan-type Location array{0: string, 1: int, 2: int}
 * @phpstan-type Reference array{line: int, column: int, length: int, kind: string, name: string, type: Type, member: ?MemberSymbol, symbol: ?TypeSymbol, declaration: Location|null}
 * @phpstan-type Receiver array{line: int, column: int, kind: string, type: Type, symbol: ?TypeSymbol, namespace: ?string, class: ?TypeSymbol}
 * @phpstan-type ScopeAt array{line: int, column: int, locals: array<string, Type>, class: ?TypeSymbol, static: bool, context: ResolutionContext}
 * @phpstan-type CallArgument array{line: int, column: int, name: ?string}
 * @phpstan-type Call array{line: int, column: int, name: string, params: list<ParameterSymbol>, returns: ?Type, arguments: list<CallArgument>}
 */
final class SemanticModel
{
    /** Identifier the language server puts where completion was requested. */
    public const PLACEHOLDER = '__sharp_complete__';

    /**
     * Names and member names whose meaning is known, by file.
     *
     * @var array<string, list<Reference>>
     */
    public array $references = [];

    /**
     * Receivers of member accesses ("user" in "user.Name"), at the position of the member name.
     *
     * @var array<string, list<Receiver>>
     */
    public array $receivers = [];

    /**
     * Names visible where the placeholder name was looked up.
     *
     * @var array<string, list<ScopeAt>>
     */
    public array $scopes = [];

    /**
     * Calls of methods and constructors, at the position of the "(" that starts their arguments: the
     * signature the editor shows while the arguments are typed.
     *
     * @var array<string, list<Call>>
     */
    public array $calls = [];

    /**
     * Errors met while analyzing; analysis goes on after them (unlike compilation).
     *
     * @var list<Diagnostic>
     */
    public array $errors = [];

    /** @var array<string, array{0: int, 1: int}> "file|scope id|name" => declaration line and column of a local */
    private array $locals = [];

    public function localDeclared(SourceFile $file, int $scopeId, string $name, Node $at): void
    {
        $this->locals[$file->path . '|' . $scopeId . '|' . $name] = [$at->line, $at->column];
    }

    /** @return Location|null */
    public function localDeclaration(SourceFile $file, int $scopeId, string $name): ?array
    {
        $at = $this->locals[$file->path . '|' . $scopeId . '|' . $name] ?? null;

        return $at === null ? null : [$file->path, $at[0], $at[1]];
    }

    /** @param Location|null $declaration */
    public function reference(SourceFile $file, Node $at, string $name, string $kind, Type $type, ?MemberSymbol $member = null, ?TypeSymbol $symbol = null, ?array $declaration = null): void
    {
        if ($at->line === 0 || $name === self::PLACEHOLDER) {
            return;
        }
        $this->references[$file->path][] = [
            'line' => $at->line, 'column' => $at->column, 'length' => mb_strlen($name), 'kind' => $kind, 'name' => $name,
            'type' => $type, 'member' => $member, 'symbol' => $symbol,
            'declaration' => $declaration ?? ($member !== null ? $member->location : $symbol?->location),
        ];
    }

    public function receiver(SourceFile $file, Node $at, string $kind, Type $type, ?TypeSymbol $symbol, ?string $namespace, ?TypeSymbol $class): void
    {
        $this->receivers[$file->path][] = [
            'line' => $at->line, 'column' => $at->column, 'kind' => $kind, 'type' => $type,
            'symbol' => $symbol, 'namespace' => $namespace, 'class' => $class,
        ];
    }

    /** @param array<string, Type> $locals */
    public function scope(SourceFile $file, Node $at, array $locals, ?TypeSymbol $class, bool $static, ResolutionContext $context): void
    {
        $this->scopes[$file->path][] = ['line' => $at->line, 'column' => $at->column, 'locals' => $locals, 'class' => $class, 'static' => $static, 'context' => $context];
    }

    /**
     * A call whose arguments start at $line:$column (the "(").
     *
     * @param string $name the callee as C# shows it: "Console.WriteLine", "string.PadLeft", or the class
     *                     name ("Random") for a constructor
     * @param list<ParameterSymbol> $params the parameters, with the type arguments known at the call
     * @param ?Type $returns the result type; null for a constructor
     * @param list<CallArgument> $arguments where each argument starts, and its name if it is a named one
     */
    public function call(SourceFile $file, int $line, int $column, string $name, array $params, ?Type $returns, array $arguments): void
    {
        if ($line === 0) {
            return;
        }
        $this->calls[$file->path][] = [
            'line' => $line, 'column' => $column, 'name' => $name, 'params' => $params, 'returns' => $returns, 'arguments' => $arguments,
        ];
    }

    /**
     * The call whose "(" is at a position (the last one recorded, if code was analyzed more than once).
     *
     * @return Call|null
     */
    public function callAt(string $path, int $line, int $column): ?array
    {
        $found = null;
        foreach ($this->calls[$path] ?? [] as $call) {
            if ($call['line'] === $line && $call['column'] === $column) {
                $found = $call;
            }
        }

        return $found;
    }

    /**
     * The reference covering a position (the innermost, i.e. last recorded, when several do).
     *
     * @return Reference|null
     */
    public function referenceAt(string $path, int $line, int $column): ?array
    {
        $found = null;
        foreach ($this->references[$path] ?? [] as $ref) {
            if ($ref['line'] === $line && $column >= $ref['column'] && $column <= $ref['column'] + $ref['length']) {
                $found = $ref;
            }
        }

        return $found;
    }

    /** @return Receiver|null */
    public function receiverAt(string $path, int $line, int $column): ?array
    {
        foreach ($this->receivers[$path] ?? [] as $receiver) {
            if ($receiver['line'] === $line && $receiver['column'] === $column) {
                return $receiver;
            }
        }

        return null;
    }

    /** @return ScopeAt|null */
    public function scopeAt(string $path, int $line, int $column): ?array
    {
        foreach ($this->scopes[$path] ?? [] as $scope) {
            if ($scope['line'] === $line && $scope['column'] === $column) {
                return $scope;
            }
        }

        return null;
    }
}
