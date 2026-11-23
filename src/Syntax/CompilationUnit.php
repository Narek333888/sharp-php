<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

use SharpPhp\Compiler\SourceFile;

/**
 * A whole .sphp file.
 */
final class CompilationUnit extends Node
{
    public function __construct(
        public readonly SourceFile $file,
        /** @var list<UsingDirective> */
        public readonly array $usings,
        /** @var list<NamespaceDecl> */
        public readonly array $namespaces,
        /** @var list<Stmt> */
        public readonly array $topLevelStatements,
        /** @var list<MethodDecl> local functions of the top-level statements: static methods of Program */
        public readonly array $localFunctions = [],
    ) {
    }
}
