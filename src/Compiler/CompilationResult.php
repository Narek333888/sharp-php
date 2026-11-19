<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

/**
 * The PHP files generated for a project and its entry point class.
 */
final class CompilationResult
{
    /**
     * @param list<GeneratedFile> $files
     * @param string|null $entryPoint FQN of the class with the static Main method
     */
    public function __construct(
        public readonly array $files,
        public readonly ?string $entryPoint,
    ) {
    }
}
