<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\Linq\Enumerable;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.HashSet<T>
 *
 * @template T
 * @sharp-element T
 *
 * @implements \IteratorAggregate<int, T>
 */
class HashSet implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @use EnumerableMethods<T> */
    use EnumerableMethods;
    use EqualityKeys;
    use SlotOrder;

    /** @var array<string, T> */
    private array $items = [];

    public int $Count {
        get => count($this->items);
    }

    /** @param iterable<T> $collection */
    public function __construct(iterable $collection = [], private readonly ?string $of = null)
    {
        foreach ($collection as $item) {
            $this->Add($item);
        }
    }

    /** @param T $item */
    public function Add(mixed $item): bool
    {
        $key = $this->keyOf($item);
        if (array_key_exists($key, $this->items)) {
            return false;
        }
        $this->items[$key] = $this->of === null ? $item : TypeCheck::check($item, $this->of);
        // (Inlined: this runs for every new item.)
        $this->version++;
        if ($key[0] === 'h') {
            $this->keyAdded($key);
        }
        if ($this->slots !== null) {
            $this->slotAdded($key);
        }

        return true;
    }

    /** @param T $item */
    public function Remove(mixed $item): bool
    {
        $key = $this->keyOf($item);
        if (!array_key_exists($key, $this->items)) {
            return false;
        }
        $this->slotRemoved($key, $this->items);
        unset($this->items[$key]);
        $this->keyRemoved($key);

        return true;
    }

    /** @param T $item */
    public function Contains(mixed $item): bool
    {
        return array_key_exists($this->keyOf($item), $this->items);
    }

    /** set.Clear(): removes every item. Like Remove, it is allowed while a foreach runs over the set. */
    public function Clear(): void
    {
        $this->items = [];
        $this->hashBuckets = [];
        $this->slotsCleared();
    }

    /** @param iterable<T> $other */
    public function UnionWith(iterable $other): void
    {
        foreach ($other as $item) {
            $this->Add($item);
        }
    }

    /** @param iterable<T> $other */
    public function IntersectWith(iterable $other): void
    {
        $keep = new self($other);
        foreach ($this->items as $key => $item) {
            if (!$keep->Contains($item)) {
                $this->slotRemoved((string) $key, $this->items);
                unset($this->items[$key]);
                $this->keyRemoved((string) $key);
            }
        }
    }

    /** @param iterable<T> $other */
    public function ExceptWith(iterable $other): void
    {
        foreach ($other as $item) {
            $this->Remove($item);
        }
    }

    /**
     * set.ToString(): the .NET type name, System.Collections.Generic.HashSet`1[System.String]
     * (System.Object when the element type is not known).
     */
    public function ToString(): string
    {
        return 'System.Collections.Generic.HashSet`1[' . TypeName::ofSpec($this->of ?? 'mixed') . ']';
    }

    /** @return \Generator<int, T> */
    public function getIterator(): \Generator
    {
        $version = $this->version;
        $removals = $this->removals;
        // Insertion order is the slot order while no slot is tracked. Items removed while enumerating
        // (which .NET allows) are skipped; an addition throws at the next step, also after the last item.
        if ($this->slots === null) {
            foreach ($this->items as $key => $item) {
                if ($version !== $this->version) {
                    $this->assertNoAdditions($version, $removals);
                    if (!array_key_exists($key, $this->items)) {
                        continue;
                    }
                }
                yield $item;
            }
        } else {
            foreach ($this->orderedHashes($this->items) as $key) {
                if ($version !== $this->version) {
                    $this->assertNoAdditions($version, $removals);
                    if (!array_key_exists($key, $this->items)) {
                        continue;
                    }
                }
                yield $this->items[$key];
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
        return $predicate === null ? count($this->items) : Enumerable::Count($this, $predicate);
    }

    private function storedAt(string $key): mixed
    {
        return $this->items[$key];
    }

    /**
     * The items in enumeration order, for LINQ (an array is faster to walk).
     *
     * @internal
     * @return list<T>
     */
    public function sequence(): array
    {
        return $this->slots === null ? array_values($this->items) : array_map(fn (string $key): mixed => $this->items[$key], $this->orderedHashes($this->items));
    }

    /**
     * json_encode() (and Laravel's JSON responses) write the items as a JSON array.
     *
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        return iterator_to_array($this, false);
    }
}
