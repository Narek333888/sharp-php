<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use RuntimeException;

/**
 * A SharpPHP source file (path + text).
 */
final class SourceFile
{
    /** @var list<string>|null */
    private ?array $lines = null;

    public function __construct(
        public readonly string $path,
        public readonly string $text,
    ) {
    }

    public static function fromPath(string $path): self
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("Cannot read source file: $path");
        }

        // Strip UTF-8 BOM.
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        return new self($path, $text);
    }

    public function line(int $number): string
    {
        $this->lines ??= preg_split('/\r\n|\n|\r/', $this->text) ?: [];

        return $this->lines[$number - 1] ?? '';
    }
}
