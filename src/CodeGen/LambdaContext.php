<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

/**
 * Variables a lambda captures from enclosing scopes.
 * Variables the lambda writes to are captured by reference (C# closure semantics),
 * the rest by value (so loop variables keep their per-iteration value).
 */
final class LambdaContext
{
    /** @var array<string, bool> name => captured by reference */
    public array $captures = [];

    public function capture(string $name, bool $write): void
    {
        $this->captures[$name] = ($this->captures[$name] ?? false) || $write;
    }
}
