<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.Stack<T> (last in, first out)
 *
 * @template T
 * @sharp-element T
 */
class Stack implements \IteratorAggregate, \Countable
{
    use EnumerableMethods;

    /** @var list<mixed> */
    private array $items = [];

    public int $Count {
        get => count($this->items);
    }

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

    /** Enumerates from the top of the stack, like C#. */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator(array_reverse($this->items));
    }

    public function count(): int
    {
        return count($this->items);
    }
}
