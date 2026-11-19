<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\ArgumentNullException;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.Dictionary<TKey, TValue>
 * Keys keep their type (unlike PHP array keys) and may be objects or enums.
 *
 * @template TKey
 * @template TValue
 * @sharp-element KeyValuePair<TKey, TValue>
 */
class Dictionary implements \ArrayAccess, \IteratorAggregate, \Countable
{
    use EnumerableMethods;

    /** @var array<string, mixed> */
    private array $keys = [];

    /** @var array<string, mixed> */
    private array $values = [];

    private readonly ?string $keyType;
    private readonly ?string $valueType;

    public int $Count {
        get => count($this->keys);
    }

    /** @var List_<TKey> */
    public List_ $Keys {
        get => new List_(array_values($this->keys));
    }

    /** @var List_<TValue> */
    public List_ $Values {
        get => new List_(array_values($this->values));
    }

    /**
     * @param iterable<mixed, mixed> $collection
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
        unset($this->keys[$hash], $this->values[$hash]);

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

    public function Clear(): void
    {
        $this->keys = [];
        $this->values = [];
    }

    public function ToString(): string
    {
        return 'System.Collections.Generic.Dictionary`2[' . TypeName::ofSpec($this->keyType ?? 'mixed') . ',' . TypeName::ofSpec($this->valueType ?? 'mixed') . ']';
    }

    // ---- PHP interfaces ----------------------------------------------

    public function getIterator(): \Iterator
    {
        foreach ($this->keys as $hash => $key) {
            yield new KeyValuePair($key, $this->values[$hash]);
        }
    }

    public function count(): int
    {
        return count($this->keys);
    }

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

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->store($this->hash($offset), $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->Remove($offset);
    }

    private function store(string $hash, mixed $key, mixed $value): void
    {
        $name = 'Dictionary<' . TypeName::ofSpec($this->keyType ?? 'mixed') . ', ' . TypeName::ofSpec($this->valueType ?? 'mixed') . '>';
        $this->keys[$hash] = $this->keyType === null ? $key : TypeCheck::check($key, $this->keyType, $name);
        $this->values[$hash] = $this->valueType === null ? $value : TypeCheck::check($value, $this->valueType, $name);
    }

    private function hash(mixed $key): string
    {
        return match (true) {
            $key === null => throw new ArgumentNullException('key'),
            is_int($key) => 'i:' . $key,
            is_string($key) => 's:' . $key,
            is_bool($key) => 'b:' . (int) $key,
            is_float($key) => 'f:' . $key,
            $key instanceof \UnitEnum => 'e:' . $key::class . '::' . $key->name,
            is_object($key) => 'o:' . spl_object_id($key),
            default => 'x:' . serialize($key),
        };
    }
}
