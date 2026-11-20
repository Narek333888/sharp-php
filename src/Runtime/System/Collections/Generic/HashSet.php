<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
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
        $key = $this->key($item);
        if (array_key_exists($key, $this->items)) {
            return false;
        }
        $this->items[$key] = $this->of === null ? $item : TypeCheck::check($item, $this->of, 'HashSet<' . TypeName::ofSpec($this->of) . '>');

        return true;
    }

    /** @param T $item */
    public function Remove(mixed $item): bool
    {
        $key = $this->key($item);
        if (!array_key_exists($key, $this->items)) {
            return false;
        }
        unset($this->items[$key]);

        return true;
    }

    /** @param T $item */
    public function Contains(mixed $item): bool
    {
        return array_key_exists($this->key($item), $this->items);
    }

    public function Clear(): void
    {
        $this->items = [];
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
                unset($this->items[$key]);
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

    public function ToString(): string
    {
        return 'System.Collections.Generic.HashSet`1[' . TypeName::ofSpec($this->of ?? 'mixed') . ']';
    }

    /** @return \Iterator<int, T> */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator(array_values($this->items));
    }

    public function count(): int
    {
        return count($this->items);
    }

    private function key(mixed $item): string
    {
        return match (true) {
            is_object($item) => 'o:' . spl_object_id($item),
            default => get_debug_type($item) . ':' . Op::str($item),
        };
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
