<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * An IEnumerable<T> computed while it is enumerated: what an iterator method (one with yield return)
 * and the deferred LINQ operators (Where, Select, Take, ...) return. As in C#, every enumeration runs
 * the code again (an iterator method's body with the arguments it was called with, a query over the
 * elements its source has then), nothing runs before the first one, and enumerating only part of it
 * (First(), Take(3)) computes only that part, so a sequence may even be endless.
 *
 * @internal
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Sequence implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @use System\Collections\Generic\EnumerableMethods<T> (for PHP code: $query->Where(...)->ToList()) */
    use System\Collections\Generic\EnumerableMethods;

    /** @param \Closure(): \Generator<mixed, T> $body */
    public function __construct(private readonly \Closure $body)
    {
    }

    /** @return \Generator<mixed, T> */
    public function getIterator(): \Generator
    {
        return ($this->body)();
    }

    /**
     * json_encode() (a Laravel controller's result) writes the elements as a JSON array.
     *
     * @return list<T>
     */
    public function jsonSerialize(): array
    {
        $items = [];
        foreach ($this as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * LINQ's Count() and Count(x => ...): enumerates the sequence. Also PHP's count() (Countable), so that
     * PHP code (a Blade view) can count a query it is given.
     */
    public function Count(?\Closure $predicate = null): int
    {
        // Not Enumerable::Count(), which would ask count() of a Countable: this method.
        $count = 0;
        foreach ($this as $item) {
            if ($predicate === null || $predicate($item)) {
                $count++;
            }
        }

        return $count;
    }

    public function ToString(): string
    {
        return 'System.Collections.Generic.IEnumerable`1';
    }
}
