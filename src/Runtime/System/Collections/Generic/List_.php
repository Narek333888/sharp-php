<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\System\Linq\Enumerable;
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

    /** What .NET's list has room for before it grows (see grow()); the count never is limited by it. */
    private int $capacity = 0;

    /** Changes with every modification: a foreach over the list throws when the list changed under it. */
    private int $version = 0;

    public int $Count {
        get => count($this->items);
    }

    /**
     * How many elements the list holds before it needs more room. Not a limit: adding more makes room,
     * as in .NET (the capacity doubles, from 4). Setting it below Count throws ArgumentOutOfRangeException.
     */
    public int $Capacity {
        get => $this->capacity;
        set {
            if ($value < count($this->items)) {
                throw new ArgumentOutOfRangeException('value', 'capacity was less than the current size.');
            }
            $this->capacity = $value;
        }
    }

    /**
     * @param iterable<T>|int|string $collection items to copy (a string's chars), or an initial capacity
     */
    public function __construct(iterable|int|string $collection = [], private readonly ?string $of = null)
    {
        if (is_string($collection)) {
            // new List<char>("abc"): a string is an IEnumerable<char> (T is char here)
            $collection = self::chars($collection);
        }
        if (is_int($collection)) {
            if ($collection < 0) {
                throw new ArgumentOutOfRangeException('capacity', 'Non-negative number required.');
            }
            $this->capacity = $collection;

            return;
        }
        $this->AddRange($collection);
    }

    /** @param T $item */
    public function Add(mixed $item): void
    {
        $this->items[] = $this->checked($item);
        $this->grow(count($this->items));
        $this->version++;
    }

    /** @param iterable<T> $collection */
    public function AddRange(iterable $collection): void
    {
        if ($collection instanceof self) {
            $collection = $collection->items; // also list.AddRange(list), which .NET allows
        }
        // A collection of known size makes room once (as .NET does for an ICollection<T>); a sequence
        // is added one element at a time.
        if (is_array($collection) || $collection instanceof \Countable) {
            $this->grow(count($this->items) + count($collection));
        }
        foreach ($collection as $item) {
            $this->Add($item);
        }
    }

    /**
     * Makes room for at least $capacity elements and returns the new capacity.
     */
    public function EnsureCapacity(int $capacity): int
    {
        if ($capacity < 0) {
            throw new ArgumentOutOfRangeException('capacity', 'Non-negative number required.');
        }
        $this->grow($capacity);

        return $this->capacity;
    }

    /** Lowers the capacity to the count when less than 90% of it is used, as .NET does. */
    public function TrimExcess(): void
    {
        if (count($this->items) < (int) ($this->capacity * 0.9)) {
            $this->capacity = count($this->items);
        }
    }

    /** @return list<T> */
    private static function chars(string $text): array
    {
        /** @var list<T> $chars the compiler passes a string only for a List<char> */
        $chars = array_map(static fn (string $c): mixed => $c, mb_str_split($text));

        return $chars;
    }

    /** .NET's List<T>.Grow: double the capacity (from 4), or more when that is not enough. */
    private function grow(int $needed): void
    {
        if ($needed <= $this->capacity) {
            return;
        }
        $this->capacity = max($this->capacity === 0 ? 4 : $this->capacity * 2, $needed);
    }

    /** @param T $item */
    public function Insert(int $index, mixed $item): void
    {
        if ($index < 0 || $index > count($this->items)) {
            throw new ArgumentOutOfRangeException('index', 'Index must be within the bounds of the List.');
        }
        array_splice($this->items, $index, 0, [$this->checked($item)]);
        $this->grow(count($this->items));
        $this->version++;
    }

    /** @param T $item */
    public function Remove(mixed $item): bool
    {
        $index = $this->IndexOf($item);
        if ($index < 0) {
            return false;
        }
        array_splice($this->items, $index, 1);
        $this->version++;

        return true;
    }

    public function RemoveAt(int $index): void
    {
        $this->assertIndex($index);
        array_splice($this->items, $index, 1);
        $this->version++;
    }

    public function RemoveAll(\Closure $match): int
    {
        $before = count($this->items);
        $this->items = array_values(array_filter($this->items, static fn (mixed $item): bool => !$match($item)));
        $removed = $before - count($this->items);
        if ($removed > 0) {
            $this->version++;
        }

        return $removed;
    }

    public function Clear(): void
    {
        $this->items = [];
        $this->version++;
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
        $version = $this->version;
        foreach ($this->items as $item) {
            $action($item);
            $this->assertUnchanged($version);
        }
    }

    /** Sorts in place, optionally with a Comparison<T> (a, b) => int. */
    public function Sort(?\Closure $comparison = null): void
    {
        usort($this->items, $comparison ?? Op::compare(...));
        $this->version++;
    }

    /** Reverses in place (C# List.Reverse()). */
    public function Reverse(): void
    {
        $this->items = array_reverse($this->items);
        $this->version++;
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

    /**
     * As in .NET, changing the list during a foreach over it (Add, Remove, list[i] = ...) throws
     * InvalidOperationException when the loop goes on.
     *
     * @return \Iterator<int, T>
     */
    public function getIterator(): \Iterator
    {
        $version = $this->version;
        foreach ($this->items as $index => $item) {
            yield $index => $item;
            $this->assertUnchanged($version);
        }
    }

    private function assertUnchanged(int $version): void
    {
        if ($version !== $this->version) {
            throw new InvalidOperationException('Collection was modified; enumeration operation may not execute.');
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
        $this->version++;
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
