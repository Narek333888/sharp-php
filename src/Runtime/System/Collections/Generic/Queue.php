<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\System\Linq\Enumerable;
use SharpPhp\Runtime\TypeCheck;

/**
 * System.Collections.Generic.Queue<T> (first in, first out)
 *
 * @template T
 * @sharp-element T
 *
 * @implements \IteratorAggregate<int, T>
 */
class Queue implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @use EnumerableMethods<T> */
    use EnumerableMethods;

    /** @var list<T> */
    private array $items = [];

    public int $Count {
        get => count($this->items);
    }

    /** @param iterable<T> $collection */
    public function __construct(iterable $collection = [], private readonly ?string $of = null)
    {
        foreach ($collection as $item) {
            $this->Enqueue($item);
        }
    }

    /** @param T $item */
    public function Enqueue(mixed $item): void
    {
        $this->items[] = $this->of === null ? $item : TypeCheck::check($item, $this->of);
    }

    /** @return T */
    public function Dequeue(): mixed
    {
        if ($this->items === []) {
            throw new InvalidOperationException('Queue empty.');
        }

        return array_shift($this->items);
    }

    /** @return T */
    public function Peek(): mixed
    {
        if ($this->items === []) {
            throw new InvalidOperationException('Queue empty.');
        }

        return $this->items[0];
    }

    public function TryDequeue(mixed &$result): bool
    {
        if ($this->items === []) {
            $result = null;

            return false;
        }
        $result = array_shift($this->items);

        return true;
    }

    public function Clear(): void
    {
        $this->items = [];
    }

    /** @return \Iterator<int, T> */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * LINQ's Count() and Count(x => ...), and Countable's count() (PHP method names ignore case,
     * so the two must be one method).
     */
    public function Count(?\Closure $predicate = null): int
    {
        return $predicate === null ? count($this->items) : Enumerable::Count($this, $predicate);
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
