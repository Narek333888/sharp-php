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

    /**
     * LINQ's Count() and Count(x => ...), and Countable's count() (PHP method names ignore case,
     * so the two must be one method).
     */
    public function Count(?\Closure $predicate = null): int
    {
        return $predicate === null ? count($this->items) : Enumerable::Count($this, $predicate);
    }

    /** group.ToString(): the type name "System.Linq.Grouping", without .NET's type arguments. */
    public function ToString(): string
    {
        return 'System.Linq.Grouping';
    }
}
