<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Linq;

use SharpPhp\Runtime\Op;

/**
 * System.Linq.IOrderedEnumerable<T>: what OrderBy and OrderByDescending return. It remembers how it
 * sorts, so that ThenBy and ThenByDescending sort the elements whose keys tie by further keys:
 *
 *   people.OrderBy(p => p.LastName).ThenBy(p => p.FirstName)
 *
 * Sorting is stable, as in LINQ: elements with equal keys keep their order. Deferred, as in LINQ: the
 * source is read and sorted when the result is enumerated, each time.
 *
 * @template T
 * @sharp-element T
 * @implements \IteratorAggregate<int, T>
 */
final class IOrderedEnumerable implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @use \SharpPhp\Runtime\System\Collections\Generic\EnumerableMethods<T> */
    use \SharpPhp\Runtime\System\Collections\Generic\EnumerableMethods;

    /**
     * @internal use OrderBy() or OrderByDescending()
     * @param iterable<T> $source
     * @param list<array{0: ?\Closure, 1: int}> $keys key selectors (null: the element itself) and directions (1 or -1), the first one deciding first
     */
    public function __construct(private readonly iterable $source, private readonly array $keys)
    {
    }

    /**
     * @internal what OrderBy (direction 1) and OrderByDescending (direction -1) return
     * @template TSource
     * @param iterable<TSource> $source
     * @return IOrderedEnumerable<TSource>
     */
    public static function orderedBy(iterable $source, ?\Closure $keySelector, int $direction): self
    {
        return new self($source, [[$keySelector, $direction]]);
    }

    /**
     * Sorts the elements whose earlier keys are equal by one more key, ascending.
     *
     * @return IOrderedEnumerable<T>
     */
    public function ThenBy(?\Closure $keySelector = null): self
    {
        return new self($this->source, [...$this->keys, [$keySelector, 1]]);
    }

    /**
     * Sorts the elements whose earlier keys are equal by one more key, descending.
     *
     * @return IOrderedEnumerable<T>
     */
    public function ThenByDescending(?\Closure $keySelector = null): self
    {
        return new self($this->source, [...$this->keys, [$keySelector, -1]]);
    }

    /**
     * The elements, sorted now.
     *
     * @internal
     * @return list<T>
     */
    public function sequence(): array
    {
        $items = array_values(Enumerable::ToArray(Enumerable::read($this->source)));
        // Each key is computed once per element (a column per key), and the positions are sorted: less
        // memory than an array per element, and numbers and strings compare without Op::compare().
        $columns = [];
        $directions = [];
        $lowered = [];
        foreach ($this->keys as $k => [$selector, $direction]) {
            $column = $selector === null ? $items : array_map($selector, $items);
            $columns[$k] = $column;
            $directions[$k] = $direction;
            $kind = self::kindOf($column);
            if ($kind === 'string') {
                $lowered[$k] = array_map(static fn (mixed $s): string => mb_strtolower((string) (is_string($s) ? $s : '')), $column);
            } elseif ($kind === 'mixed') {
                $lowered[$k] = null;
            }
        }
        $order = array_keys($items);
        usort($order, static function (int $a, int $b) use ($columns, $directions, $lowered): int {
            foreach ($columns as $k => $column) {
                if (!array_key_exists($k, $lowered)) {
                    $c = $column[$a] <=> $column[$b];                                   // numbers
                } elseif ($lowered[$k] !== null) {
                    // As Op::compareStrings (strcmp, not <=>, which compares numeric strings as numbers).
                    $c = (strcmp($lowered[$k][$a], $lowered[$k][$b]) <=> 0)
                        ?: (strcmp(is_string($x = $column[$b]) ? $x : '', is_string($y = $column[$a]) ? $y : '') <=> 0);
                } else {
                    $c = Op::compare($column[$a], $column[$b]);
                }
                if ($c !== 0) {
                    return $c * $directions[$k];
                }
            }

            return $a <=> $b; // stable
        });

        return array_map(static fn (int $i): mixed => $items[$i], $order);
    }

    /**
     * "number" when every key is an int or a float, "string" when every key is a string, else "mixed".
     *
     * @param array<mixed> $column
     */
    private static function kindOf(array $column): string
    {
        $numbers = true;
        $strings = true;
        foreach ($column as $value) {
            $numbers = $numbers && (is_int($value) || is_float($value));
            $strings = $strings && is_string($value);
            if (!$numbers && !$strings) {
                return 'mixed';
            }
        }

        return $numbers ? 'number' : 'string';
    }

    /** @return \Iterator<int, T> */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->sequence());
    }

    /** LINQ's Count() and Count(x => ...), and PHP's count() (Countable). */
    public function Count(?\Closure $predicate = null): int
    {
        return $predicate === null ? count(Enumerable::ToArray(Enumerable::read($this->source))) : Enumerable::Count($this->sequence(), $predicate);
    }

    /** @return list<T> */
    public function jsonSerialize(): array
    {
        return $this->sequence();
    }

    /** ordered.ToString(): the type name "System.Linq.OrderedEnumerable`2", without .NET's type arguments. */
    public function ToString(): string
    {
        return 'System.Linq.OrderedEnumerable`2';
    }
}
