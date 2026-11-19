<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

/**
 * A compiler error with its location, printed in MSBuild style:
 *   src/Program.sphp(12,5): error SP0103: The name 'x' does not exist in the current context
 */
final class Diagnostic
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?SourceFile $file = null,
        public readonly int $line = 0,
        public readonly int $column = 0,
    ) {
    }

    public function format(?string $baseDir = null): string
    {
        $location = '';
        if ($this->file !== null) {
            $path = $this->file->path;
            if ($baseDir !== null) {
                $base = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
                if (str_starts_with($path, $base)) {
                    $path = substr($path, strlen($base));
                }
            }
            $location = $this->line > 0 ? "$path({$this->line},{$this->column}): " : "$path: ";
        }

        return "{$location}error {$this->code}: {$this->message}";
    }

    /** The offending source line with a caret under the error column. */
    public function codeFrame(): string
    {
        if ($this->file === null || $this->line <= 0) {
            return '';
        }

        $source = rtrim($this->file->line($this->line));
        $gutter = str_pad((string) $this->line, 5, ' ', STR_PAD_LEFT) . ' | ';
        $prefix = mb_substr($source, 0, max(0, $this->column - 1));
        $padding = preg_replace('/[^\t]/u', ' ', $prefix) ?? '';

        return $gutter . $source . PHP_EOL
            . str_repeat(' ', 5) . ' | ' . $padding . '^';
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
