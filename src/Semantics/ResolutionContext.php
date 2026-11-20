<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

use SharpPhp\Compiler\SourceFile;

/**
 * Where a name is resolved from: the enclosing namespace and the active using directives.
 */
final class ResolutionContext
{
    /** Namespaces that are always imported (like C# implicit usings). */
    /** As .NET's implicit usings: System, collections, LINQ, tasks and HttpClient need no using directive. */
    public const IMPLICIT_USINGS = ['System', 'System.Collections.Generic', 'System.Linq', 'System.Threading.Tasks', 'System.Net.Http'];

    /**
     * @param list<string> $usings namespaces (dotted)
     * @param array<string, string> $aliases alias => dotted name
     */
    public function __construct(
        public readonly SourceFile $file,
        public readonly string $namespace,
        public readonly array $usings,
        public readonly array $aliases,
    ) {
    }

    public function phpNamespace(): string
    {
        return Names::phpNamespace($this->namespace);
    }

    /**
     * Namespaces whose members are in scope without a qualifier, dotted: the enclosing namespace and
     * the ones around it ("App.Models", "App", and "" for the global namespace), then the imported
     * ones. C# looks for extension methods in exactly these.
     *
     * @return list<string>
     */
    public function namespacesInScope(): array
    {
        $parts = $this->namespace === '' ? [] : explode('.', $this->namespace);
        $enclosing = [];
        for ($length = count($parts); $length > 0; $length--) {
            $enclosing[] = implode('.', array_slice($parts, 0, $length));
        }
        $enclosing[] = '';

        return array_values(array_unique([...$enclosing, ...$this->usings, ...self::IMPLICIT_USINGS]));
    }
}
