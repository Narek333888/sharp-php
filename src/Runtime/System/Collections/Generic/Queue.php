<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\TypeCheck;
use SharpPhp\Runtime\TypeName;

/**
 * System.Collections.Generic.Queue<T> (first in, first out)
 *
 * @template T
 * @sharp-element T
 */
class Queue implements \IteratorAggregate, \Countable
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
            $this->Enqueue($item);
        }
    }

    /** @param T $item */
    public function Enqueue(mixed $item): void
    {
        $this->items[] = $this->of === null ? $item : TypeCheck::check($item, $this->of, 'Queue<' . TypeName::ofSpec($this->of) . '>');
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

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
