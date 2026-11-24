<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\ArgumentNullException;
use SharpPhp\Runtime\System\Linq\Enumerable;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.Dictionary<TKey, TValue>
 * Keys keep their type (unlike PHP array keys) and may be objects or enums.
 *
 * @template TKey
 * @template TValue
 * @sharp-element KeyValuePair<TKey, TValue>
 *
 * @implements \ArrayAccess<TKey, TValue>
 * @implements \IteratorAggregate<int, KeyValuePair<TKey, TValue>>
 */
class Dictionary implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @use EnumerableMethods<KeyValuePair<TKey, TValue>> */
    use EnumerableMethods;
    use EqualityKeys;
    use SlotOrder;

    /** @var array<string, TKey> */
    private array $keys = [];

    /** @var array<string, TValue> */
    private array $values = [];

    private readonly ?string $keyType;
    private readonly ?string $valueType;

    public int $Count {
        get => count($this->keys);
    }

    /** @var List_<TKey> */
    public List_ $Keys {
        get => new List_(array_map(fn (string $h): mixed => $this->keys[$h], $this->orderedHashes($this->keys)));
    }

    /** @var List_<TValue> */
    public List_ $Values {
        get => new List_(array_map(fn (string $h): mixed => $this->values[$h], $this->orderedHashes($this->keys)));
    }

    /**
     * @param iterable<mixed, mixed> $collection pairs, or key => value
     * @param array{0: string, 1: string}|null $of key and value type specs
     */
    public function __construct(iterable $collection = [], ?array $of = null)
    {
        $this->keyType = $of[0] ?? null;
        $this->valueType = $of[1] ?? null;
        foreach ($collection as $key => $value) {
            if ($value instanceof KeyValuePair) {
                $this->Add($value->Key, $value->Value);
            } else {
                $this->Add($key, $value);
            }
        }
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function Add(mixed $key, mixed $value): void
    {
        $hash = $this->hash($key);
        if (array_key_exists($hash, $this->keys)) {
            throw new ArgumentException('An item with the same key has already been added. Key: ' . Op::str($key));
        }
        $this->store($hash, $key, $value);
    }

    /**
     * dict.TryAdd(key, value): adds the entry and returns true; returns false, keeping the stored
     * value, when the key is already there. ArgumentNullException for a null key.
     */
    public function TryAdd(mixed $key, mixed $value): bool
    {
        $hash = $this->hash($key);
        if (array_key_exists($hash, $this->keys)) {
            return false;
        }
        $this->store($hash, $key, $value);

        return true;
    }

    /** @param TKey $key */
    public function ContainsKey(mixed $key): bool
    {
        return array_key_exists($this->hash($key), $this->keys);
    }

    /** @param TValue $value */
    public function ContainsValue(mixed $value): bool
    {
        foreach ($this->values as $existing) {
            if (Op::equals($existing, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param TKey $key */
    public function Remove(mixed $key): bool
    {
        $hash = $this->hash($key);
        if (!array_key_exists($hash, $this->keys)) {
            return false;
        }
        $this->slotRemoved($hash, $this->keys);
        unset($this->keys[$hash], $this->values[$hash]);
        $this->keyRemoved($hash);

        return true;
    }

    /**
     * @param TKey $key
     * @param-out TValue|null $value
     */
    public function TryGetValue(mixed $key, mixed &$value): bool
    {
        $hash = $this->hash($key);
        if (array_key_exists($hash, $this->keys)) {
            $value = $this->values[$hash];

            return true;
        }
        $value = null;

        return false;
    }

    /** @return TValue|null */
    public function GetValueOrDefault(mixed $key, mixed $defaultValue = null): mixed
    {
        $hash = $this->hash($key);

        return array_key_exists($hash, $this->keys) ? $this->values[$hash] : $defaultValue;
    }

    /** dict.Clear(): removes every entry; like Remove, allowed while a foreach runs over the dictionary, as in .NET. */
    public function Clear(): void
    {
        $this->keys = [];
        $this->values = [];
        $this->hashBuckets = [];
        $this->slotsCleared();
    }

    /**
     * dict.ToString(): the .NET type name, System.Collections.Generic.Dictionary`2[System.String,System.Int32]
     * (System.Object where a type is not known).
     */
    public function ToString(): string
    {
        return 'System.Collections.Generic.Dictionary`2[' . TypeName::ofSpec($this->keyType ?? 'mixed') . ',' . TypeName::ofSpec($this->valueType ?? 'mixed') . ']';
    }

    // ---- PHP interfaces ----------------------------------------------

    /** @return \Iterator<int, KeyValuePair<TKey, TValue>> */
    public function getIterator(): \Iterator
    {
        $version = $this->version;
        $removals = $this->removals;
        // Insertion order is the slot order while no slot is tracked. Entries removed while enumerating
        // (which .NET allows) are skipped; an addition throws at the next step, also after the last entry.
        if ($this->slots === null) {
            foreach ($this->keys as $hash => $key) {
                if ($version !== $this->version) {
                    $this->assertNoAdditions($version, $removals);
                    if (!array_key_exists($hash, $this->keys)) {
                        continue;
                    }
                }
                yield new KeyValuePair($key, $this->values[$hash]);
            }
        } else {
            foreach ($this->orderedHashes($this->keys) as $hash) {
                if ($version !== $this->version) {
                    $this->assertNoAdditions($version, $removals);
                    if (!array_key_exists($hash, $this->keys)) {
                        continue;
                    }
                }
                yield new KeyValuePair($this->keys[$hash], $this->values[$hash]);
            }
        }
        if ($version !== $this->version) {
            $this->assertNoAdditions($version, $removals);
        }
    }

    /**
     * LINQ's Count() and Count(x => ...), and Countable's count() (PHP method names ignore case,
     * so the two must be one method).
     */
    public function Count(?\Closure $predicate = null): int
    {
        return $predicate === null ? count($this->keys) : Enumerable::Count($this, $predicate);
    }

    /** PHP's isset(dict[key]): whether the key is there, as dict.ContainsKey(key). */
    public function offsetExists(mixed $offset): bool
    {
        return $this->ContainsKey($offset);
    }

    /** @return TValue */
    public function offsetGet(mixed $offset): mixed
    {
        $hash = $this->hash($offset);
        if (!array_key_exists($hash, $this->keys)) {
            throw new KeyNotFoundException("The given key '" . Op::str($offset) . "' was not present in the dictionary.");
        }

        return $this->values[$hash];
    }

    /**
     * dict[key] = value: adds the entry, or replaces the value of an existing key (the stored key
     * stays); ArgumentNullException for a null key.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->store($this->hash($offset), $offset, $value);
    }

    /** PHP's unset(dict[key]): removes the entry, as dict.Remove(key); nothing happens when the key is missing. */
    public function offsetUnset(mixed $offset): void
    {
        $this->Remove($offset);
    }

    private function store(string $hash, mixed $key, mixed $value): void
    {
        $new = !array_key_exists($hash, $this->keys);
        if ($new) {
            // A key equal to a stored one keeps the stored one, as in .NET.
            $this->keys[$hash] = $this->keyType === null ? $key : TypeCheck::check($key, $this->keyType);
        }
        $this->values[$hash] = $this->valueType === null ? $value : TypeCheck::check($value, $this->valueType);
        if ($new) {
            // (Inlined: this runs for every new key.)
            $this->version++;
            if ($hash[0] === 'h') {
                $this->keyAdded($hash);
            }
            if ($this->slots !== null) {
                $this->slotAdded($hash);
            }
        }
    }

    private function storedAt(string $key): mixed
    {
        return $this->keys[$key];
    }

    private function hash(mixed $key): string
    {
        return $key === null ? throw new ArgumentNullException('key') : $this->keyOf($key);
    }

    /** json_encode() (and Laravel's JSON responses) write a JSON object: {"key": value, ...}. */
    public function jsonSerialize(): \stdClass
    {
        $object = new \stdClass();
        foreach ($this as $pair) {
            $object->{Op::str($pair->Key)} = $pair->Value;
        }

        return $object;
    }
}
