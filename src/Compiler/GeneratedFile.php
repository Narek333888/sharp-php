<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

/**
 * PHP output for one source file.
 */
final class GeneratedFile
{
    /**
     * @param array<int, int> $lineMap generated line => source line
     * @param list<string> $classes FQNs declared by this file
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $code,
        public readonly array $lineMap,
        public readonly array $classes,
    ) {
    }
}
