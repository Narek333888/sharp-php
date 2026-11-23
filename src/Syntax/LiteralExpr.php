<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * kind: int | float | string | char | bool | null
 */
final class LiteralExpr extends Expr
{
    public function __construct(
        public readonly string $kind,
        public readonly mixed $value,
        /** An integer literal's C# type: int, uint, long or ulong (from its suffix and its size). */
        public readonly string $integral = 'int',
        /** A real literal's C# type: double, float (1.5f) or decimal (1.5m). */
        public readonly string $floating = 'double',
    ) {
    }

    /** The value of a string or char literal. */
    public function stringValue(): string
    {
        return is_string($this->value) ? $this->value : throw new \LogicException("A {$this->kind} literal is not a string");
    }

    public function intValue(): int
    {
        return is_int($this->value) ? $this->value : throw new \LogicException("A {$this->kind} literal is not an int");
    }

    /** The value of a numeric literal as a float (2 => 2.0). */
    public function floatValue(): float
    {
        return is_int($this->value) || is_float($this->value) ? (float) $this->value : throw new \LogicException("A {$this->kind} literal is not a number");
    }
}
