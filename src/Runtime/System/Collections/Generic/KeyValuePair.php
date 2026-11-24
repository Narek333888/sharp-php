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
 * @implements \ArrayAccess<int, mixed> kv[0] is the key, kv[1] the value
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

    /** pair.ToString(): "[key, value]", as in .NET. */
    public function ToString(): string
    {
        return '[' . Op::str($this->Key) . ', ' . Op::str($this->Value) . ']';
    }

    /** PHP's isset(pair[i]): true for 0 (the Key) and 1 (the Value), the positions deconstruction reads. */
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 0 || $offset === 1;
    }

    /** pair[0] is the Key, pair[1] (or any other offset) the Value: what foreach (var (key, value) in dict) reads. */
    public function offsetGet(mixed $offset): mixed
    {
        return $offset === 0 ? $this->Key : $this->Value;
    }

    /** pair[i] = value: not allowed (PHP's LogicException), a KeyValuePair is immutable as in .NET. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('KeyValuePair is immutable.');
    }

    /** PHP's unset(pair[i]): not allowed (PHP's LogicException), a KeyValuePair is immutable as in .NET. */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('KeyValuePair is immutable.');
    }
}
