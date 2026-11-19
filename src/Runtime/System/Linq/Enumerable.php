<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Linq;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\List_;
use SharpPhp\Runtime\System\InvalidOperationException;

/**
 * System.Linq.Enumerable: LINQ to Objects over arrays and any iterable.
 * Arrays use it directly (numbers.Where(...) => Enumerable::Where($numbers, ...)).
 *
 * @template TSource
 */
final class Enumerable
{
    /** @return List_<TSource> */
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

    /** @return List_<mixed> */
    public static function Select(iterable $source, \Closure $selector): List_
    {
        $result = [];
        $i = 0;
        foreach ($source as $item) {
            $result[] = $selector($item, $i++);
        }

        return new List_($result);
    }

    /** @return List_<mixed> */
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

    public static function Any(iterable $source, ?\Closure $predicate = null): bool
    {
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                return true;
            }
        }

        return false;
    }

    public static function All(iterable $source, \Closure $predicate): bool
    {
        foreach ($source as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }

        return true;
    }

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

    public static function Contains(iterable $source, mixed $value): bool
    {
        foreach ($source as $item) {
            if (Op::equals($item, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @return TSource */
    public static function First(iterable $source, ?\Closure $predicate = null): mixed
    {
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                return $item;
            }
        }

        throw new InvalidOperationException($predicate === null ? 'Sequence contains no elements' : 'Sequence contains no matching element');
    }

    /** @return TSource|null */
    public static function FirstOrDefault(iterable $source, ?\Closure $predicate = null, mixed $defaultValue = null): mixed
    {
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                return $item;
            }
        }

        return $defaultValue;
    }

    /** @return TSource */
    public static function Last(iterable $source, ?\Closure $predicate = null): mixed
    {
        $found = false;
        $last = null;
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                $found = true;
                $last = $item;
            }
        }
        if (!$found) {
            throw new InvalidOperationException($predicate === null ? 'Sequence contains no elements' : 'Sequence contains no matching element');
        }

        return $last;
    }

    /** @return TSource|null */
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

    /** @return TSource */
    public static function Single(iterable $source, ?\Closure $predicate = null): mixed
    {
        $matches = self::Where($source, $predicate ?? static fn (): bool => true)->ToArray();
        if (count($matches) !== 1) {
            throw new InvalidOperationException(count($matches) === 0 ? 'Sequence contains no elements' : 'Sequence contains more than one element');
        }

        return $matches[0];
    }

    /** @return TSource */
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

    public static function Sum(iterable $source, ?\Closure $selector = null): int|float
    {
        $sum = 0;
        foreach ($source as $item) {
            $sum += $selector === null ? $item : $selector($item);
        }

        return $sum;
    }

    public static function Average(iterable $source, ?\Closure $selector = null): float
    {
        $sum = 0;
        $count = 0;
        foreach ($source as $item) {
            $sum += $selector === null ? $item : $selector($item);
            $count++;
        }
        if ($count === 0) {
            throw new InvalidOperationException('Sequence contains no elements');
        }

        return $sum / $count;
    }

    public static function Min(iterable $source, ?\Closure $selector = null): mixed
    {
        return self::extreme($source, $selector, -1);
    }

    public static function Max(iterable $source, ?\Closure $selector = null): mixed
    {
        return self::extreme($source, $selector, 1);
    }

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

    /** @return List_<TSource> */
    public static function OrderBy(iterable $source, ?\Closure $keySelector = null): List_
    {
        return self::sort($source, $keySelector, 1);
    }

    /** @return List_<TSource> */
    public static function OrderByDescending(iterable $source, ?\Closure $keySelector = null): List_
    {
        return self::sort($source, $keySelector, -1);
    }

    private static function sort(iterable $source, ?\Closure $keySelector, int $direction): List_
    {
        $items = self::materialize($source);
        $keyed = [];
        foreach ($items as $i => $item) {
            $keyed[] = [$keySelector === null ? $item : $keySelector($item), $i, $item];
        }
        // Stable sort, like LINQ.
        usort($keyed, static fn (array $a, array $b): int => (Op::compare($a[0], $b[0]) * $direction) ?: ($a[1] <=> $b[1]));

        return new List_(array_column($keyed, 2));
    }

    /** @return List_<TSource> */
    public static function Take(iterable $source, int $count): List_
    {
        return new List_(array_slice(self::materialize($source), 0, max(0, $count)));
    }

    /** @return List_<TSource> */
    public static function Skip(iterable $source, int $count): List_
    {
        return new List_(array_slice(self::materialize($source), max(0, $count)));
    }

    /** @return List_<TSource> */
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

    /** @return List_<TSource> */
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

    /** @return List_<TSource> */
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

    /** @return List_<TSource> */
    public static function Reverse(iterable $source): List_
    {
        return new List_(array_reverse(self::materialize($source)));
    }

    /** @return List_<TSource> */
    public static function Concat(iterable $first, iterable $second): List_
    {
        return new List_([...self::materialize($first), ...self::materialize($second)]);
    }

    /** @return List_<TSource> */
    public static function ToList(iterable $source): List_
    {
        return new List_(self::materialize($source));
    }

    /** @return TSource[] */
    public static function ToArray(iterable $source): array
    {
        return self::materialize($source);
    }

    public static function ToDictionary(iterable $source, \Closure $keySelector, ?\Closure $elementSelector = null): Dictionary
    {
        $dictionary = new Dictionary();
        foreach ($source as $item) {
            $dictionary->Add($keySelector($item), $elementSelector === null ? $item : $elementSelector($item));
        }

        return $dictionary;
    }

    /** @return List_<Grouping> */
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

    public static function Aggregate(iterable $source, mixed $seedOrFunc, ?\Closure $func = null): mixed
    {
        $items = self::materialize($source);
        if ($func === null) {
            if ($items === []) {
                throw new InvalidOperationException('Sequence contains no elements');
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

    /** @return list<mixed> */
    private static function materialize(iterable $source): array
    {
        if (is_array($source)) {
            return array_values($source);
        }
        if ($source instanceof List_) {
            return $source->ToArray();
        }
        $result = [];
        foreach ($source as $item) {
            $result[] = $item;
        }

        return $result;
    }
}
