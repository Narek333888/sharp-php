<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

/**
 * Indented line writer that remembers which source line produced each PHP line.
 *
 * Code produced by nested writers (closure bodies) can be embedded in an expression with
 * {@see embedded()}; the line markers survive and are decoded again by {@see line()}.
 */
final class CodeWriter
{
    private const MARK = "\x01";
    private const INDENT = '    ';

    /** @var list<array{0: string, 1: int}> */
    private array $lines = [];

    private int $indent = 0;

    public int $sourceLine = 0;

    public function line(string $text = ''): void
    {
        foreach (explode("\n", $text) as $i => $part) {
            $sourceLine = $this->sourceLine;
            if (str_starts_with($part, self::MARK)) {
                $end = strpos($part, self::MARK, 1);
                $sourceLine = (int) substr($part, 1, $end - 1) ?: $sourceLine;
                $part = substr($part, $end + 1);
            }
            $this->lines[] = [$part === '' ? '' : str_repeat(self::INDENT, $this->indent) . $part, $sourceLine];
        }
    }

    /** Appends the lines of another writer at the current indentation. */
    public function append(CodeWriter $other): void
    {
        foreach ($other->lines as [$text, $source]) {
            $this->lines[] = [$text === '' ? '' : str_repeat(self::INDENT, $this->indent) . $text, $source];
        }
    }

    public function indent(): void
    {
        $this->indent++;
    }

    public function outdent(): void
    {
        $this->indent--;
    }

    public function blank(): void
    {
        if ($this->lines !== [] && $this->lines[array_key_last($this->lines)][0] !== '') {
            $this->lines[] = ['', $this->sourceLine];
        }
    }

    /** All lines as text, for embedding inside an expression of an outer writer. */
    public function embedded(): string
    {
        return implode("\n", array_map(
            static fn (array $l): string => self::MARK . $l[1] . self::MARK . $l[0],
            $this->lines,
        ));
    }

    public function code(): string
    {
        return implode("\n", array_column($this->lines, 0)) . "\n";
    }

    /**
     * Generated line number (1-based) => source line number.
     *
     * @return array<int, int>
     */
    public function lineMap(int $offset = 0): array
    {
        $map = [];
        foreach ($this->lines as $i => [, $source]) {
            if ($source > 0) {
                $map[$i + 1 + $offset] = $source;
            }
        }

        return $map;
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }
}
