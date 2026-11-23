<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;

/**
 * How HashSet and Dictionary find a value, as .NET's EqualityComparer<T>.Default does: numbers, strings,
 * bools and enums by value; objects with their own GetHashCode() (records, structs, DateTime, Guid,
 * TimeSpan, classes that override it) by Equals() among those with the same hash code; other objects
 * by identity.
 *
 * Each value has a string key (Op::key()); an object compared by Equals() gets the key of an equal
 * stored one, or a new key "h:<hash code>:<object id>", listed under its hash code.
 *
 * @internal
 */
trait EqualityKeys
{
    /** @var array<int, list<string>> hash code => the keys stored under it */
    private array $hashBuckets = [];

    /** The stored value a key stands for (HashSet: the item; Dictionary: the key). */
    abstract private function storedAt(string $key): mixed;

    /** The key of $value: the one of an equal stored value, else a key it could be stored under. */
    private function keyOf(mixed $value): string
    {
        // The common keys first, without a call.
        if (is_int($value)) {
            return 'i:' . $value;
        }
        if (is_string($value)) {
            return 's:' . $value;
        }
        $key = Op::key($value);
        if ($key !== null) {
            return $key;
        }
        $hash = Op::hashCode($value);
        foreach ($this->hashBuckets[$hash] ?? [] as $candidate) {
            if (Op::equals($this->storedAt($candidate), $value)) {
                return $candidate;
            }
        }

        return 'h:' . $hash . ':' . (is_object($value) ? spl_object_id($value) : 0);
    }

    /** After a value was stored under a new key. */
    private function keyAdded(string $key): void
    {
        if (str_starts_with($key, 'h:')) {
            $this->hashBuckets[(int) explode(':', $key, 3)[1]][] = $key;
        }
    }

    /** After the value of a key was removed. */
    private function keyRemoved(string $key): void
    {
        if (!str_starts_with($key, 'h:')) {
            return;
        }
        $hash = (int) explode(':', $key, 3)[1];
        $bucket = array_values(array_filter($this->hashBuckets[$hash] ?? [], static fn (string $k): bool => $k !== $key));
        if ($bucket === []) {
            unset($this->hashBuckets[$hash]);
        } else {
            $this->hashBuckets[$hash] = $bucket;
        }
    }
}
