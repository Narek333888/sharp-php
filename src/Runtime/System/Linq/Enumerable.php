<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Linq;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\ArgumentOutOfRangeException;
use SharpPhp\Runtime\Sequence;
use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\HashSet;
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
     * The elements the predicate accepts (it may take the index too: (x, i) => ...). Deferred, as in
     * LINQ: the source is read when the result is enumerated, and again each time.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function Where(iterable $source, \Closure $predicate): iterable
    {
        return new Sequence(static function () use ($source, $predicate): \Generator {
            $i = 0;
            foreach (self::read($source) as $item) {
                if ($predicate($item, $i++)) {
                    yield $item;
                }
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<mixed>
     */
    public static function Select(iterable $source, \Closure $selector): iterable
    {
        return new Sequence(static function () use ($source, $selector): \Generator {
            $i = 0;
            foreach (self::read($source) as $item) {
                yield $selector($item, $i++);
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @param \Closure(TSource, int): mixed $selector returns a sequence (or a string: its chars)
     * @return iterable<mixed>
     */
    public static function SelectMany(iterable $source, \Closure $selector): iterable
    {
        return new Sequence(static function () use ($source, $selector): \Generator {
            $i = 0;
            foreach (self::read($source) as $item) {
                $inner = $selector($item, $i++);
                foreach (is_string($inner) ? mb_str_split($inner) : (is_iterable($inner) ? self::read($inner) : []) as $element) {
                    yield $element;
                }
            }
        });
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
        // Stops at a second match, as LINQ does (so it ends on an endless sequence that has two).
        $found = false;
        $match = null;
        foreach ($source as $item) {
            if ($predicate === null || $predicate($item)) {
                if ($found) {
                    throw new InvalidOperationException($predicate === null ? 'Sequence contains more than one element' : 'Sequence contains more than one matching element');
                }
                [$found, $match] = [true, $item];
            }
        }
        if (!$found) {
            throw new InvalidOperationException($predicate === null ? 'Sequence contains no elements' : 'Sequence contains no matching element');
        }

        /** @var TSource $match */
        return $match;
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
            $sum += self::number($selector === null ? $item : $selector($item)); // a null counts 0, as .NET skips it
        }

        return $sum;
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     */
    public static function Average(iterable $source, ?\Closure $selector = null): ?float
    {
        // As .NET: nulls (of int?, double?, ...) are skipped; no value at all gives null, which the
        // compiler turns into InvalidOperationException for a sequence of a non-nullable type.
        $sum = 0;
        $count = 0;
        foreach ($source as $item) {
            $value = $selector === null ? $item : $selector($item);
            if ($value === null) {
                continue;
            }
            $sum += self::number($value);
            $count++;
        }

        return $count === 0 ? null : $sum / $count;
    }

    /**
     * Min(), Max() and Average() of a sequence of a non-nullable value type: InvalidOperationException
     * for no elements (their result is null then).
     *
     * @internal the compiler emits it
     * @template T
     * @param T|null $result
     * @return T
     */
    public static function required(mixed $result): mixed
    {
        return $result ?? throw new InvalidOperationException('Sequence contains no elements');
    }

    /**
     * Sum() of ints and longs is checked, as in .NET: OverflowException past the type's range.
     *
     * @internal the compiler emits it
     * @param 'int'|'long' $type
     */
    public static function checkedSum(int|float $sum, string $type): int
    {
        if (is_float($sum) || ($type === 'int' && ($sum > 2147483647 || $sum < -2147483648))) {
            throw new \SharpPhp\Runtime\System\OverflowException('Arithmetic operation resulted in an overflow.');
        }

        return $sum;
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
        // As .NET: nulls are skipped (int?, strings); no value at all gives null (see required()).
        $best = null;
        $found = false;
        foreach ($source as $item) {
            $value = $selector === null ? $item : $selector($item);
            if ($value === null) {
                continue;
            }
            if (!$found || Op::compare($value, $best) === $direction) {
                $best = $value;
                $found = true;
            }
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
     * @return iterable<TResult>
     */
    public static function Zip(iterable $first, iterable $second, \Closure $resultSelector): iterable
    {
        return new Sequence(static function () use ($first, $second, $resultSelector): \Generator {
            $other = self::iterator($second);
            $other->rewind();
            foreach (self::read($first) as $item) {
                if (!$other->valid()) {
                    return;
                }
                yield $resultSelector($item, $other->current());
                $other->next();
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function Take(iterable $source, int $count): iterable
    {
        return new Sequence(static function () use ($source, $count): \Generator {
            if ($count <= 0) {
                return;
            }
            $taken = 0;
            foreach (self::read($source) as $item) {
                yield $item;
                if (++$taken >= $count) {
                    return;
                }
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function Skip(iterable $source, int $count): iterable
    {
        return new Sequence(static function () use ($source, $count): \Generator {
            $skipped = 0;
            foreach (self::read($source) as $item) {
                if ($skipped < $count) {
                    $skipped++;
                    continue;
                }
                yield $item;
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function TakeWhile(iterable $source, \Closure $predicate): iterable
    {
        return new Sequence(static function () use ($source, $predicate): \Generator {
            $i = 0;
            foreach (self::read($source) as $item) {
                if (!$predicate($item, $i++)) {
                    return;
                }
                yield $item;
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function SkipWhile(iterable $source, \Closure $predicate): iterable
    {
        return new Sequence(static function () use ($source, $predicate): \Generator {
            $skipping = true;
            $i = 0;
            foreach (self::read($source) as $item) {
                if ($skipping && $predicate($item, $i++)) {
                    continue;
                }
                $skipping = false;
                yield $item;
            }
        });
    }

    /**
     * The elements without repeats (equal by Equals(), as a HashSet compares them), in order.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function Distinct(iterable $source): iterable
    {
        return new Sequence(static function () use ($source): \Generator {
            $seen = new HashSet();
            foreach (self::read($source) as $item) {
                if ($seen->Add($item)) {
                    yield $item;
                }
            }
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function Reverse(iterable $source): iterable
    {
        return new Sequence(static function () use ($source): \Generator {
            yield from array_reverse(self::materialize(self::read($source)));
        });
    }

    /**
     * @template TSource
     * @param iterable<TSource> $first
     * @param iterable<TSource> $second
     * @return iterable<TSource>
     */
    public static function Concat(iterable $first, iterable $second): iterable
    {
        return new Sequence(static function () use ($first, $second): \Generator {
            foreach (self::read($first) as $item) {
                yield $item;
            }
            foreach (self::read($second) as $item) {
                yield $item;
            }
        });
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
     * The elements in groups of equal keys (by Equals()), in the order the keys first appear.
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<Grouping>
     */
    public static function GroupBy(iterable $source, \Closure $keySelector): iterable
    {
        return new Sequence(static function () use ($source, $keySelector): \Generator {
            /** @var Dictionary<mixed, int> $index */
            $index = new Dictionary();
            $keys = [];
            $groups = [];
            foreach (self::read($source) as $item) {
                $key = $keySelector($item);
                if ($key === null) {
                    $i = $nullGroup ??= count($keys);
                } elseif (!$index->TryGetValue($key, $i)) {
                    $i = count($keys);
                    $index[$key] = $i;
                }
                if (!isset($groups[$i])) {
                    $keys[$i] = $key;
                    $groups[$i] = [];
                }
                $groups[$i][] = $item;
            }
            foreach ($keys as $i => $key) {
                yield new Grouping($key, $groups[$i]);
            }
        });
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
     * @return iterable<TSource>
     */
    public static function DistinctBy(iterable $source, \Closure $keySelector): iterable
    {
        return new Sequence(static function () use ($source, $keySelector): \Generator {
            $seen = new HashSet();
            foreach (self::read($source) as $item) {
                if ($seen->Add($keySelector($item))) {
                    yield $item;
                }
            }
        });
    }

    /**
     * The elements in arrays of $size (the last one may be shorter): [1, 2, 3].Chunk(2) is [1, 2], [3].
     *
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource[]>
     */
    public static function Chunk(iterable $source, int $size): iterable
    {
        if ($size < 1) {
            throw new ArgumentOutOfRangeException('size');
        }

        return new Sequence(static function () use ($source, $size): \Generator {
            $chunk = [];
            foreach (self::read($source) as $item) {
                $chunk[] = $item;
                if (count($chunk) === $size) {
                    yield $chunk;
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                yield $chunk;
            }
        });
    }

    /** @return iterable<int> */
    public static function Range(int $start, int $count): iterable
    {
        if ($count < 0 || $start + $count - 1 > 2147483647) {
            throw new ArgumentOutOfRangeException('count');
        }

        return new Sequence(static function () use ($start, $count): \Generator {
            for ($i = 0; $i < $count; $i++) {
                yield $start + $i;
            }
        });
    }

    /** @return iterable<mixed> */
    public static function Repeat(mixed $element, int $count): iterable
    {
        if ($count < 0) {
            throw new ArgumentOutOfRangeException('count');
        }

        return new Sequence(static function () use ($element, $count): \Generator {
            for ($i = 0; $i < $count; $i++) {
                yield $element;
            }
        });
    }

    /**
     * What a deferred operator reads when it is enumerated: a collection's current elements (a List's
     * array: faster to walk than its iterator), so that a query sees the changes made after it was
     * written, as in LINQ.
     *
     * @internal
     * @template TSource
     * @param iterable<TSource> $source
     * @return iterable<TSource>
     */
    public static function read(iterable $source): iterable
    {
        if (is_object($source) && method_exists($source, 'sequence')) {
            $elements = $source->sequence();
            if (is_iterable($elements)) {
                return $elements;
            }
        }

        return $source;
    }

    /**
     * @param iterable<mixed> $source
     * @return \Iterator<mixed, mixed>
     */
    private static function iterator(iterable $source): \Iterator
    {
        $source = self::read($source);
        while ($source instanceof \IteratorAggregate) {
            $source = $source->getIterator();
        }

        return match (true) {
            is_array($source) => new \ArrayIterator($source),
            $source instanceof \Iterator => $source,
            default => new \IteratorIterator($source),
        };
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
