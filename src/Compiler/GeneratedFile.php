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
     * @param string $part "" for the file of the source; a class name for a class with static
     *                     initialization, which has a file of its own (loaded, and initialized, when the
     *                     class is first used, as C# runs a static constructor)
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $code,
        public readonly array $lineMap,
        public readonly array $classes,
        public readonly string $part = '',
    ) {
    }
}
