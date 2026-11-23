<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\System\Linq\Enumerable;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.Stack<T> (last in, first out)
 *
 * @template T
 * @sharp-element T
 *
 * @implements \IteratorAggregate<int, T>
 */
class Stack implements \IteratorAggregate, \Countable, \JsonSerializable
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
            $this->Push($item);
        }
    }

    /** @param T $item */
    public function Push(mixed $item): void
    {
        $this->items[] = $this->of === null ? $item : TypeCheck::check($item, $this->of, 'Stack<' . TypeName::ofSpec($this->of) . '>');
    }

    /** @return T */
    public function Pop(): mixed
    {
        if ($this->items === []) {
            throw new InvalidOperationException('Stack empty.');
        }

        return array_pop($this->items);
    }

    /** @return T */
    public function Peek(): mixed
    {
        if ($this->items === []) {
            throw new InvalidOperationException('Stack empty.');
        }

        return $this->items[array_key_last($this->items)];
    }

    public function Clear(): void
    {
        $this->items = [];
    }

    /**
     * Enumerates from the top of the stack, like C#.
     *
     * @return \Iterator<int, T>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator(array_reverse($this->items));
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
