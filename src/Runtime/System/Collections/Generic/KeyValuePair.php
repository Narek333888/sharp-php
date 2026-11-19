<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;

/**
 * System.Collections.Generic.KeyValuePair<TKey, TValue>
 * Supports deconstruction: foreach (var (key, value) in dictionary)
 *
 * @template TKey
 * @template TValue
 */
final class KeyValuePair implements \ArrayAccess
{
    public function __construct(
        /** @var TKey */
        public readonly mixed $Key,
        /** @var TValue */
        public readonly mixed $Value,
    ) {
    }

    public function ToString(): string
    {
        return '[' . Op::str($this->Key) . ', ' . Op::str($this->Value) . ']';
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset === 0 || $offset === 1;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $offset === 0 ? $this->Key : $this->Value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('KeyValuePair is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('KeyValuePair is immutable.');
    }
}
