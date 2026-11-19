<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Linq;


/**
 * Result element of GroupBy: a key plus the items that share it.
 *
 * @sharp-element mixed
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Grouping implements \IteratorAggregate, \Countable
{
    /** @use \SharpPhp\Runtime\System\Collections\Generic\EnumerableMethods<mixed> */
    use \SharpPhp\Runtime\System\Collections\Generic\EnumerableMethods;

    /** @param list<mixed> $items */
    public function __construct(
        public readonly mixed $Key,
        private readonly array $items,
    ) {
    }

    /** @return \Iterator<int, mixed> */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function ToString(): string
    {
        return 'System.Linq.Grouping';
    }
}
