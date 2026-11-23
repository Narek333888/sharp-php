<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

use SharpPhp\Compiler\CompileError;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\TypeRef;

/**
 * Resolves type names written in source to semantic types.
 */
final class TypeResolver
{
    /** C# keyword types. */
    public const KEYWORDS = [
        'int' => 'int', 'long' => 'int', 'short' => 'int', 'byte' => 'int', 'sbyte' => 'int', 'uint' => 'int',
        'ulong' => 'int', 'ushort' => 'int', 'float' => 'float', 'double' => 'float', 'decimal' => 'float',
        'bool' => 'bool', 'string' => 'string', 'char' => 'char', 'object' => 'mixed', 'void' => 'void',
        'dynamic' => 'mixed',
    ];

    /** System type names that are really primitives. */
    private const SYSTEM_PRIMITIVES = [
        'String' => 'string', 'Char' => 'char', 'Int16' => 'int', 'Int32' => 'int', 'Int64' => 'int',
        'UInt16' => 'int', 'UInt32' => 'int', 'UInt64' => 'int', 'Byte' => 'int', 'SByte' => 'int',
        'Single' => 'float', 'Double' => 'float', 'Decimal' => 'float', 'Boolean' => 'bool', 'Object' => 'mixed',
    ];

    /** Keyword type used as an expression (int.Parse) => runtime helper class. */
    public const KEYWORD_RUNTIME_CLASSES = [
        'string' => 'System.String', 'char' => 'System.Char', 'int' => 'System.Int32', 'long' => 'System.Int64',
        'short' => 'System.Int16', 'byte' => 'System.Byte', 'sbyte' => 'System.SByte', 'uint' => 'System.UInt32',
        'ulong' => 'System.UInt64', 'ushort' => 'System.UInt16', 'double' => 'System.Double', 'float' => 'System.Double',
        'decimal' => 'System.Double', 'bool' => 'System.Boolean', 'object' => 'System.Object',
    ];

    private const DELEGATES = ['Func', 'Action', 'Predicate', 'Comparison', 'Converter', 'EventHandler'];

    private const SEQUENCE_INTERFACES = [
        'IEnumerable', 'IEnumerator', 'ICollection', 'IList', 'IReadOnlyList', 'IReadOnlyCollection',
    ];

    public function __construct(private readonly SymbolTable $symbols)
    {
    }

    /**
     * @param array<string, Type> $typeParams type parameters in scope
     */
    public function resolve(TypeRef $ref, ResolutionContext $ctx, array $typeParams = []): Type
    {
        if ($ref->name === '[]') {
            return Type::array($this->resolve($ref->args[0], $ctx, $typeParams))->withNullable($ref->nullable);
        }

        $args = array_map(fn (TypeRef $a): Type => $this->resolve($a, $ctx, $typeParams), $ref->args);

        if (isset(self::KEYWORDS[$ref->name])) {
            return $this->primitive(self::KEYWORDS[$ref->name])->withNullable($ref->nullable);
        }

        if (isset($typeParams[$ref->name]) && $ref->args === []) {
            return $typeParams[$ref->name]->withNullable($ref->nullable);
        }

        $simple = str_starts_with($ref->name, 'System.') ? substr($ref->name, 7) : $ref->name;
        // A project type named like a System alias (class Single) shadows it, as in C#.
        if (isset(self::SYSTEM_PRIMITIVES[$simple]) && $args === [] && !$this->isProjectType($ref->name, $ctx)) {
            return $this->primitive(self::SYSTEM_PRIMITIVES[$simple])->withNullable($ref->nullable);
        }
        if (in_array($simple, self::DELEGATES, true)) {
            if ($simple === 'Action') {
                $args[] = Type::void();
            } elseif ($simple === 'Predicate') {
                $args[] = Type::bool();
            }

            return Type::closure($args)->withNullable($ref->nullable);
        }
        $collectionSimple = str_starts_with($simple, 'Collections.Generic.') ? substr($simple, 20) : $simple;
        if (in_array($collectionSimple, self::SEQUENCE_INTERFACES, true)) {
            return Type::iterable($args[0] ?? Type::mixed())->withNullable($ref->nullable);
        }

        $symbol = $this->findClass($ref->name, $ctx, $ref);
        if ($symbol === null) {
            throw CompileError::at(
                'SP0246',
                "The type or namespace name '{$ref->name}' could not be found (are you missing a using directive?)",
                $ctx->file,
                $ref->line,
                $ref->column,
            );
        }

        if ($symbol->kind === 'delegate') {
            return $this->delegateType($symbol, $args)->withNullable($ref->nullable);
        }

        return Type::class($symbol->fqn, $args, $ref->nullable);
    }

    /** @var array<string, true> delegate types being resolved (delegate D Next(D d) refers to itself) */
    private array $resolvingDelegates = [];

    /**
     * delegate int Transform(int x): the closure type (int) => int, as Func<int, int> is. Generic
     * delegates bind their type parameters to the arguments: Producer<string> for delegate T Producer<T>().
     *
     * @param list<Type> $args
     */
    private function delegateType(TypeSymbol $symbol, array $args): Type
    {
        $decl = $symbol->delegateDecl;
        $ctx = $symbol->delegateContext;
        $invoke = $decl?->members[0] ?? null;
        if ($decl === null || $ctx === null || !$invoke instanceof \SharpPhp\Syntax\MethodDecl || isset($this->resolvingDelegates[$symbol->fqn])) {
            return Type::closure([]);
        }
        $this->resolvingDelegates[$symbol->fqn] = true;
        try {
            $map = [];
            foreach ($decl->typeParams as $i => $name) {
                $map[$name] = $args[$i] ?? Type::typeParam($name);
            }
            $types = [];
            foreach ($invoke->params as $param) {
                $types[] = $param->type !== null ? $this->resolve($param->type, $ctx, $map) : Type::mixed();
            }
            $types[] = $this->resolve($invoke->returnType ?? new TypeRef('void', [], false), $ctx, $map);

            return Type::closure($types);
        } finally {
            unset($this->resolvingDelegates[$symbol->fqn]);
        }
    }

    private function isProjectType(string $name, ResolutionContext $ctx): bool
    {
        $symbol = str_starts_with($name, 'System.') ? null : $this->findClass($name, $ctx);

        return $symbol !== null && !$symbol->external;
    }

    public function primitive(string $kind): Type
    {
        return match ($kind) {
            'int' => Type::int(),
            'float' => Type::float(),
            'string' => Type::string(),
            'char' => Type::char(),
            'bool' => Type::bool(),
            'void' => Type::void(),
            default => Type::mixed(),
        };
    }

    /**
     * Finds a class by C# name using C# lookup rules:
     * enclosing namespaces first, then using directives, then the global namespace.
     */
    public function findClass(string $dotted, ResolutionContext $ctx, ?Node $at = null): ?TypeSymbol
    {
        $first = explode('.', $dotted, 2)[0];
        if (isset($ctx->aliases[$first])) {
            $rest = substr($dotted, strlen($first));
            $found = $this->symbols->find(Names::phpClass($ctx->aliases[$first] . $rest));
            if ($found !== null) {
                return $found;
            }
        }

        // Enclosing namespaces: A.B.C => A.B.C.X, A.B.X, A.X
        $ns = $ctx->namespace;
        while ($ns !== '') {
            $found = $this->symbols->find(Names::phpClass($ns . '.' . $dotted));
            if ($found !== null) {
                return $found;
            }
            $pos = strrpos($ns, '.');
            $ns = $pos === false ? '' : substr($ns, 0, $pos);
        }

        if (!str_contains($dotted, '.')) {
            $matches = [];
            foreach (array_unique([...$ctx->usings, ...ResolutionContext::IMPLICIT_USINGS]) as $using) {
                $found = $this->symbols->find(Names::phpClass($using . '.' . $dotted));
                if ($found !== null) {
                    $matches[strtolower($found->fqn)] = $found;
                }
            }
            if (count($matches) > 1 && $at !== null) {
                $names = implode("' and '", array_map(static fn (TypeSymbol $s): string => $s->csharpName(), $matches));
                throw CompileError::at('SP0104', "'$dotted' is an ambiguous reference between '$names'", $ctx->file, $at->line, $at->column);
            }
            if ($matches !== []) {
                return reset($matches);
            }
        }

        return $this->symbols->find(Names::phpClass($dotted));
    }
}
