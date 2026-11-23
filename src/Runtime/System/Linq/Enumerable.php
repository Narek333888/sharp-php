<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Linq;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\List_;
use SharpPhp\Runtime\System\InvalidCastException;
use SharpPhp\Runtime\System\InvalidOperationException;

/**
 * System.Linq.Enumerable: LINQ to Objects over arrays and any iterable.
 * Arrays use it directly (numbers.Where(...) => Enumerable::Where($numbers, ...)).
 * Methods are generic over the element type TSource, which the compiler infers from the receiver.
 */
final class Enumerable
{
    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function Where(iterable $source, \Closure $predicate): List_
    {
        $result = [];
        $i = 0;
        foreach ($source as $item) {
            if ($predicate($item, $i++)) {
                $result[] = $item;
            }
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<mixed>
     */
    public static function Select(iterable $source, \Closure $selector): List_
    {
        $result = [];
        $i = 0;
        foreach ($source as $item) {
            $result[] = $selector($item, $i++);
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @param \Closure(TSource): iterable<mixed> $selector
     * @return List_<mixed>
     */
    public static function SelectMany(iterable $source, \Closure $selector): List_
    {
        $result = [];
        foreach ($source as $item) {
            foreach ($selector($item) as $inner) {
                $result[] = $inner;
            }
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Any(iterable $source, ?\Closure $predicate = null): bool
    {
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function All(iterable $source, \Closure $predicate): bool
    {
        foreach ($source as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return int<0, max>
     */
    public static function Count(iterable $source, ?\Closure $predicate = null): int
    {
        if ($predicate === null && (is_array($source) || $source instanceof \Countable)) {
            return count($source);
        }
        $count = 0;
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Contains(iterable $source, mixed $value): bool
    {
        foreach ($source as $item) {
            if (Op::equals($item, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource
     */
    public static function First(iterable $source, ?\Closure $predicate = null): mixed
    {
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                return $item;
            }
        }

        throw new InvalidOperationException($predicate === null ? 'Sequence contains no elements' : 'Sequence contains no matching element');
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource|null
     */
    public static function FirstOrDefault(iterable $source, ?\Closure $predicate = null, mixed $defaultValue = null): mixed
    {
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                return $item;
            }
        }

        return $defaultValue;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource
     */
    public static function Last(iterable $source, ?\Closure $predicate = null): mixed
    {
        $last = []; // [item] once a match is found (the item itself may be null)
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                $last = [$item];
            }
        }
        if ($last === []) {
            throw new InvalidOperationException($predicate === null ? 'Sequence contains no elements' : 'Sequence contains no matching element');
        }

        return $last[0];
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource|null
     */
    public static function LastOrDefault(iterable $source, ?\Closure $predicate = null): mixed
    {
        $last = null;
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                $last = $item;
            }
        }

        return $last;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource
     */
    public static function Single(iterable $source, ?\Closure $predicate = null): mixed
    {
        $matches = self::Where($source, $predicate ?? static fn (): bool => true)->ToArray();
        if (count($matches) !== 1) {
            throw new InvalidOperationException(count($matches) === 0 ? 'Sequence contains no elements' : 'Sequence contains more than one element');
        }

        return $matches[0];
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource
     */
    public static function ElementAt(iterable $source, int $index): mixed
    {
        $i = 0;
        foreach ($source as $item) {
            if ($i++ === $index) {
                return $item;
            }
        }

        throw new ArgumentOutOfRangeException('index', 'Index was out of range. Must be non-negative and less than the size of the collection.');
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Sum(iterable $source, ?\Closure $selector = null): int|float
    {
        $sum = 0;
        foreach ($source as $item) {
            $sum += self::number($selector === null ? $item : $selector($item));
        }

        return $sum;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Average(iterable $source, ?\Closure $selector = null): float
    {
        $sum = 0;
        $count = 0;
        foreach ($source as $item) {
            $sum += self::number($selector === null ? $item : $selector($item));
            $count++;
        }
        if ($count === 0) {
            throw new InvalidOperationException('Sequence contains no elements');
        }

        return $sum / $count;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Min(iterable $source, ?\Closure $selector = null): mixed
    {
        return self::extreme($source, $selector, -1);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Max(iterable $source, ?\Closure $selector = null): mixed
    {
        return self::extreme($source, $selector, 1);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    private static function extreme(iterable $source, ?\Closure $selector, int $direction): mixed
    {
        $best = null;
        $found = false;
        foreach ($source as $item) {
            $value = $selector === null ? $item : $selector($item);
            if (!$found || Op::compare($value, $best) === $direction) {
                $best = $value;
                $found = true;
            }
        }
        if (!$found) {
            throw new InvalidOperationException('Sequence contains no elements');
        }

        return $best;
    }

    /**
     * Sorted by a key, ascending and stable; ThenBy() sorts ties further.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return IOrderedEnumerable<TSource>
     */
    public static function OrderBy(iterable $source, ?\Closure $keySelector = null): IOrderedEnumerable
    {
        return IOrderedEnumerable::orderedBy($source, $keySelector, 1);
    }

    /**
     * Sorted by a key, descending and stable; ThenBy() sorts ties further.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return IOrderedEnumerable<TSource>
     */
    public static function OrderByDescending(iterable $source, ?\Closure $keySelector = null): IOrderedEnumerable
    {
        return IOrderedEnumerable::orderedBy($source, $keySelector, -1);
    }

    /**
     * first.Zip(second, (a, b) => ...): the selector's results for the elements at the same positions,
     * as far as the shorter sequence goes.
     *
     * @template TFirst
     * @template TSecond
     * @template TResult
     * @param iterable<TFirst> $first
     * @param iterable<TSecond> $second
     * @param \Closure(TFirst, TSecond): TResult $resultSelector
     * @return List_<TResult>
     */
    public static function Zip(iterable $first, iterable $second, \Closure $resultSelector): List_
    {
        $a = self::materialize($first);
        $b = self::materialize($second);
        $result = [];
        for ($i = 0, $count = min(count($a), count($b)); $i < $count; $i++) {
            $result[] = $resultSelector($a[$i], $b[$i]);
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function Take(iterable $source, int $count): List_
    {
        return new List_(array_slice(self::materialize($source), 0, max(0, $count)));
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function Skip(iterable $source, int $count): List_
    {
        return new List_(array_slice(self::materialize($source), max(0, $count)));
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function TakeWhile(iterable $source, \Closure $predicate): List_
    {
        $result = [];
        foreach ($source as $item) {
            if (!$predicate($item)) {
                break;
            }
            $result[] = $item;
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function SkipWhile(iterable $source, \Closure $predicate): List_
    {
        $result = [];
        $skipping = true;
        foreach ($source as $item) {
            if ($skipping && $predicate($item)) {
                continue;
            }
            $skipping = false;
            $result[] = $item;
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function Distinct(iterable $source): List_
    {
        $result = [];
        foreach ($source as $item) {
            if (!self::Contains($result, $item)) {
                $result[] = $item;
            }
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function Reverse(iterable $source): List_
    {
        return new List_(array_reverse(self::materialize($source)));
    }

    /**
     * @template TSource
     * @param iterable<TSource> $first
     * @template TSource
     * @param iterable<TSource> $second
     * @return List_<TSource>
     */
    public static function Concat(iterable $first, iterable $second): List_
    {
        return new List_([...self::materialize($first), ...self::materialize($second)]);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function ToList(iterable $source): List_
    {
        return new List_(self::materialize($source));
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource[]
     */
    public static function ToArray(iterable $source): array
    {
        return self::materialize($source);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @phpstan-return Dictionary<mixed, mixed>
     */
    public static function ToDictionary(iterable $source, \Closure $keySelector, ?\Closure $elementSelector = null): Dictionary
    {
        $dictionary = new Dictionary();
        foreach ($source as $item) {
            $dictionary->Add($keySelector($item), $elementSelector === null ? $item : $elementSelector($item));
        }

        return $dictionary;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<Grouping>
     */
    public static function GroupBy(iterable $source, \Closure $keySelector): List_
    {
        $groups = [];
        $keys = [];
        foreach ($source as $item) {
            $key = $keySelector($item);
            $index = array_search($key, $keys, true);
            if ($index === false) {
                $keys[] = $key;
                $index = array_key_last($keys);
                $groups[$index] = [];
            }
            $groups[$index][] = $item;
        }
        $result = [];
        foreach ($keys as $i => $key) {
            $result[] = new Grouping($key, $groups[$i]);
        }

        return new List_($result);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Aggregate(iterable $source, mixed $seedOrFunc, ?\Closure $func = null): mixed
    {
        $items = self::materialize($source);
        if ($func === null) {
            if ($items === []) {
                throw new InvalidOperationException('Sequence contains no elements');
            }
            if (!$seedOrFunc instanceof \Closure) {
                throw new ArgumentException('Aggregate needs an accumulator function.', 'func');
            }
            $accumulator = array_shift($items);
            $func = $seedOrFunc;
        } else {
            $accumulator = $seedOrFunc;
        }
        foreach ($items as $item) {
            $accumulator = $func($accumulator, $item);
        }

        return $accumulator;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $first
     * @param iterable<mixed> $second
     */
    public static function SequenceEqual(iterable $first, iterable $second): bool
    {
        $a = self::materialize($first);
        $b = self::materialize($second);
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $item) {
            if (!Op::equals($item, $b[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The element with the smallest key (the first of equal ones); null for an empty sequence.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource|null
     */
    public static function MinBy(iterable $source, \Closure $keySelector): mixed
    {
        return self::extremeBy($source, $keySelector, -1);
    }

    /**
     * The element with the largest key (the first of equal ones); null for an empty sequence.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource|null
     */
    public static function MaxBy(iterable $source, \Closure $keySelector): mixed
    {
        return self::extremeBy($source, $keySelector, 1);
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return TSource|null
     */
    private static function extremeBy(iterable $source, \Closure $keySelector, int $direction): mixed
    {
        $best = null;
        $bestKey = null;
        $found = false;
        foreach ($source as $item) {
            $key = $keySelector($item);
            if (!$found || Op::compare($key, $bestKey) === $direction) {
                [$best, $bestKey, $found] = [$item, $key, true];
            }
        }

        return $best;
    }

    /**
     * The elements whose key was not seen before, in order.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource>
     */
    public static function DistinctBy(iterable $source, \Closure $keySelector): List_
    {
        $keys = [];
        $result = [];
        foreach ($source as $item) {
            $key = $keySelector($item);
            if (!self::Contains($keys, $key)) {
                $keys[] = $key;
                $result[] = $item;
            }
        }

        return new List_($result);
    }

    /**
     * The elements in arrays of $size (the last one may be shorter): [1, 2, 3].Chunk(2) is [1, 2], [3].
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return List_<TSource[]>
     */
    public static function Chunk(iterable $source, int $size): List_
    {
        if ($size < 1) {
            throw new ArgumentOutOfRangeException('size');
        }

        /** @var List_<TSource[]> $chunks */
        $chunks = new List_(array_chunk(self::materialize($source), $size));

        return $chunks;
    }

    /** @return List_<int> */
    public static function Range(int $start, int $count): List_
    {
        if ($count < 0) {
            throw new ArgumentOutOfRangeException('count');
        }

        return new List_($count === 0 ? [] : range($start, $start + $count - 1));
    }

    /** @return List_<mixed> */
    public static function Repeat(mixed $element, int $count): List_
    {
        return new List_(array_fill(0, max(0, $count), $element));
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return list<TSource>
     */
    private static function materialize(iterable $source): array
    {
        if (is_array($source)) {
            return array_values($source);
        }
        $result = [];
        foreach ($source as $item) {
            $result[] = $item;
        }

        return $result;
    }

    /** An element (or selected value) that Sum/Average add up: C# only sums numbers. */
    private static function number(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if ($value === null) {
            return 0; // Sum over int? skips nulls
        }

        throw new InvalidCastException(sprintf("Unable to cast object of type '%s' to type 'System.Double'.", \SharpPhp\Runtime\TypeName::of($value)));
    }
}
