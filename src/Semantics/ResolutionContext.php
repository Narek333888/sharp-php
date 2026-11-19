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
    public const IMPLICIT_USINGS = ['System', 'System.Collections.Generic', 'System.Linq'];

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
}
