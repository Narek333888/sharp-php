<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.List<T>
 *
 * new List<int>() compiles to new List_(of: 'int'): the element type is checked at runtime.
 *
 * @template T
 * @sharp-element T
 *
 * @implements \ArrayAccess<int, T>
 * @implements \IteratorAggregate<int, T>
 */
class List_ implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @use EnumerableMethods<T> */
    use EnumerableMethods;

    /** @var list<T> */
    private array $items = [];

    public int $Count {
        get => count($this->items);
    }

    /**
     * @param iterable<T>|int $collection items to copy, or an initial capacity
     */
    public function __construct(iterable|int $collection = [], private readonly ?string $of = null)
    {
        if (is_iterable($collection)) {
            foreach ($collection as $item) {
                $this->items[] = $this->checked($item);
            }
        }
    }

    /** @param T $item */
    public function Add(mixed $item): void
    {
        $this->items[] = $this->checked($item);
    }

    /** @param iterable<T> $collection */
    public function AddRange(iterable $collection): void
    {
        foreach ($collection as $item) {
            $this->items[] = $this->checked($item);
        }
    }

    /** @param T $item */
    public function Insert(int $index, mixed $item): void
    {
        if ($index < 0 || $index > count($this->items)) {
            throw new ArgumentOutOfRangeException('index', 'Index must be within the bounds of the List.');
        }
        array_splice($this->items, $index, 0, [$this->checked($item)]);
    }

    /** @param T $item */
    public function Remove(mixed $item): bool
    {
        $index = $this->IndexOf($item);
        if ($index < 0) {
            return false;
        }
        array_splice($this->items, $index, 1);

        return true;
    }

    public function RemoveAt(int $index): void
    {
        $this->assertIndex($index);
        array_splice($this->items, $index, 1);
    }

    public function RemoveAll(\Closure $match): int
    {
        $before = count($this->items);
        $this->items = array_values(array_filter($this->items, static fn (mixed $item): bool => !$match($item)));

        return $before - count($this->items);
    }

    public function Clear(): void
    {
        $this->items = [];
    }

    /** @param T $item */
    public function Contains(mixed $item): bool
    {
        return $this->IndexOf($item) >= 0;
    }

    /** @param T $item */
    public function IndexOf(mixed $item): int
    {
        foreach ($this->items as $i => $existing) {
            if (Op::equals($existing, $item)) {
                return $i;
            }
        }

        return -1;
    }

    /** @return T|null */
    public function Find(\Closure $match): mixed
    {
        foreach ($this->items as $item) {
            if ($match($item)) {
                return $item;
            }
        }

        return null;
    }

    /** @return List_<T> */
    public function FindAll(\Closure $match): List_
    {
        return new self(array_filter($this->items, static fn (mixed $item): bool => (bool) $match($item)), $this->of);
    }

    public function FindIndex(\Closure $match): int
    {
        foreach ($this->items as $i => $item) {
            if ($match($item)) {
                return $i;
            }
        }

        return -1;
    }

    public function Exists(\Closure $match): bool
    {
        return $this->FindIndex($match) >= 0;
    }

    public function TrueForAll(\Closure $match): bool
    {
        foreach ($this->items as $item) {
            if (!$match($item)) {
                return false;
            }
        }

        return true;
    }

    public function ForEach(\Closure $action): void
    {
        foreach ($this->items as $item) {
            $action($item);
        }
    }

    /** Sorts in place, optionally with a Comparison<T> (a, b) => int. */
    public function Sort(?\Closure $comparison = null): void
    {
        usort($this->items, $comparison ?? Op::compare(...));
    }

    /** Reverses in place (C# List.Reverse()). */
    public function Reverse(): void
    {
        $this->items = array_reverse($this->items);
    }

    /** @return List_<T> */
    public function GetRange(int $index, int $count): List_
    {
        if ($index < 0 || $count < 0 || $index + $count > count($this->items)) {
            throw new ArgumentOutOfRangeException('index');
        }

        return new self(array_slice($this->items, $index, $count), $this->of);
    }

    /** @return T[] */
    public function ToArray(): array
    {
        return $this->items;
    }

    public function ToString(): string
    {
        return 'System.Collections.Generic.List`1[' . TypeName::ofSpec($this->of ?? 'mixed') . ']';
    }

    // ---- PHP interfaces ----------------------------------------------

    /** @return \Iterator<int, T> */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @param mixed $offset any value: only valid indexes exist */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < count($this->items);
    }

    /** @return T */
    public function offsetGet(mixed $offset): mixed
    {
        $this->assertIndex($offset);

        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->Add($value);

            return;
        }
        $this->assertIndex($offset);
        // An existing index (checked above) is replaced, so $items stays a list; proving that would
        // need copying the array on every write.
        $this->items[$offset] = $this->checked($value); // @phpstan-ignore assign.propertyType
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->RemoveAt($offset);
    }

    /** @phpstan-assert int<0, max> $index */
    private function assertIndex(mixed $index): void
    {
        if (!is_int($index) || $index < 0 || $index >= count($this->items)) {
            throw new ArgumentOutOfRangeException('index', 'Index was out of range. Must be non-negative and less than the size of the collection.');
        }
    }

    /**
     * An item to store, checked against the element type when the list knows it (new List<int>()).
     *
     * @return T
     */
    private function checked(mixed $item): mixed
    {
        $checked = $this->of === null ? $item : TypeCheck::check($item, $this->of, 'List<' . TypeName::ofSpec($this->of) . '>');
        /** @var T $checked the generated code only stores elements of the list's type */
        return $checked;
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
