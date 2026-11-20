<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Linq;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\Collections\Generic\List_;

/**
 * System.Linq.IOrderedEnumerable<T>: what OrderBy and OrderByDescending return. A list that remembers how
 * it was sorted, so that ThenBy and ThenByDescending sort the elements whose keys tie by further keys:
 *
 *   people.OrderBy(p => p.LastName).ThenBy(p => p.FirstName)
 *
 * Sorting is stable, as in LINQ: elements with equal keys keep their order.
 *
 * @template T
 * @sharp-element T
 * @extends List_<T>
 */
final class IOrderedEnumerable extends List_
{
    /**
     * @internal use OrderBy() or OrderByDescending()
     * @param iterable<T> $collection the elements, already sorted by $keys
     * @param list<array{0: ?\Closure, 1: int}> $keys key selectors (null: the element itself) and directions (1 or -1), the first one deciding first
     */
    public function __construct(iterable $collection = [], private readonly array $keys = [])
    {
        parent::__construct($collection);
    }

    /**
     * @internal what OrderBy (direction 1) and OrderByDescending (direction -1) return
     * @template TSource
     * @param iterable<TSource> $source
     * @return IOrderedEnumerable<TSource>
     */
    public static function orderedBy(iterable $source, ?\Closure $keySelector, int $direction): self
    {
        return self::sortBy(array_values(Enumerable::ToArray($source)), [[$keySelector, $direction]]);
    }

    /**
     * Sorts the elements whose earlier keys are equal by one more key, ascending.
     *
     * @return IOrderedEnumerable<T>
     */
    public function ThenBy(?\Closure $keySelector = null): self
    {
        return self::sortBy(array_values($this->ToArray()), [...$this->keys, [$keySelector, 1]]);
    }

    /**
     * Sorts the elements whose earlier keys are equal by one more key, descending.
     *
     * @return IOrderedEnumerable<T>
     */
    public function ThenByDescending(?\Closure $keySelector = null): self
    {
        return self::sortBy(array_values($this->ToArray()), [...$this->keys, [$keySelector, -1]]);
    }

    /**
     * @template TItem
     * @param list<TItem> $items
     * @param list<array{0: ?\Closure, 1: int}> $keys
     * @return IOrderedEnumerable<TItem>
     */
    private static function sortBy(array $items, array $keys): self
    {
        $keyed = [];
        foreach ($items as $i => $item) {
            // Each key is computed once per element, not once per comparison.
            $values = array_map(static fn (array $key): mixed => $key[0] === null ? $item : ($key[0])($item), $keys);
            $keyed[] = [$values, $i, $item];
        }
        usort($keyed, static function (array $a, array $b) use ($keys): int {
            foreach ($keys as $k => [, $direction]) {
                $order = Op::compare($a[0][$k], $b[0][$k]) * $direction;
                if ($order !== 0) {
                    return $order;
                }
            }

            return $a[1] <=> $b[1];
        });

        return new self(array_column($keyed, 2), $keys);
    }
}
