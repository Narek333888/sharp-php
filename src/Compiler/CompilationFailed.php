<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use RuntimeException;

/**
 * Thrown when a build produced one or more diagnostics.
 */
final class CompilationFailed extends RuntimeException
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        $count = count($diagnostics);
        parent::__construct("Build failed with $count error(s):" . PHP_EOL . implode(PHP_EOL, array_map(
            static fn (Diagnostic $d): string => $d->format(),
            $diagnostics,
        )));
    }

    public function render(?string $baseDir = null): string
    {
        $out = [];
        foreach ($this->diagnostics as $diagnostic) {
            $out[] = $diagnostic->format($baseDir);
            $frame = $diagnostic->codeFrame();
            if ($frame !== '') {
                $out[] = $frame;
            }
        }
        $count = count($this->diagnostics);
        $out[] = '';
        $out[] = "Build FAILED. $count error(s).";

        return implode(PHP_EOL, $out);
    }
}
