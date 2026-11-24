<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\System\Linq\Enumerable;
use SharpPhp\Runtime\System\Linq\IOrderedEnumerable;

/**
 * LINQ methods available on every runtime collection (list.Where(...), set.Any(), ...).
 * T is the element type of the using class, which declares "@use EnumerableMethods<T>".
 *
 * @template T
 */
trait EnumerableMethods
{
    /**
     * What the LINQ methods read: the collection itself, or (List) its array, which is faster to
     * walk than an iterator and needs no copy (PHP copies arrays only when one is changed). The
     * deferred operators (Where, Select, ...) get the collection and read this when they are
     * enumerated, so that a query sees the elements the collection has then, as in LINQ.
     *
     * @internal
     * @return iterable<T>
     */
    public function sequence(): iterable
    {
        return $this;
    }

    /** @return iterable<T> */
    public function Where(\Closure $predicate): iterable
    {
        return Enumerable::Where($this, $predicate);
    }

    /** @return T|null */
    public function MinBy(\Closure $keySelector): mixed
    {
        return Enumerable::MinBy($this->sequence(), $keySelector);
    }

    /** @return T|null */
    public function MaxBy(\Closure $keySelector): mixed
    {
        return Enumerable::MaxBy($this->sequence(), $keySelector);
    }

    /** @return iterable<T> */
    public function DistinctBy(\Closure $keySelector): iterable
    {
        return Enumerable::DistinctBy($this, $keySelector);
    }

    /** @return iterable<T[]> */
    public function Chunk(int $size): iterable
    {
        return Enumerable::Chunk($this, $size);
    }

    /** @return iterable<mixed> */
    public function Select(\Closure $selector): iterable
    {
        return Enumerable::Select($this, $selector);
    }

    /** @return iterable<mixed> */
    public function SelectMany(\Closure $selector): iterable
    {
        return Enumerable::SelectMany($this, $selector);
    }

    /** source.Any() or source.Any(x => ...): whether there is an element (one that matches); stops at the first. */
    public function Any(?\Closure $predicate = null): bool
    {
        return Enumerable::Any($this->sequence(), $predicate);
    }

    /** source.All(x => ...): whether every element matches; stops at the first that does not; true for no elements. */
    public function All(\Closure $predicate): bool
    {
        return Enumerable::All($this->sequence(), $predicate);
    }

    // No Count() here: PHP method names ignore case, so it would clash with Countable's count();
    // each collection declares Count(?Closure $predicate = null), which is both.

    /** @return T */
    public function First(?\Closure $predicate = null): mixed
    {
        return Enumerable::First($this->sequence(), $predicate);
    }

    /** @return T|null */
    public function FirstOrDefault(?\Closure $predicate = null, mixed $defaultValue = null): mixed
    {
        return Enumerable::FirstOrDefault($this->sequence(), $predicate, $defaultValue);
    }

    /** @return T */
    public function Last(?\Closure $predicate = null): mixed
    {
        return Enumerable::Last($this->sequence(), $predicate);
    }

    /** @return T|null */
    public function LastOrDefault(?\Closure $predicate = null): mixed
    {
        return Enumerable::LastOrDefault($this->sequence(), $predicate);
    }

    /** @return T */
    public function Single(?\Closure $predicate = null): mixed
    {
        return Enumerable::Single($this->sequence(), $predicate);
    }

    /** @return T */
    public function ElementAt(int $index): mixed
    {
        return Enumerable::ElementAt($this->sequence(), $index);
    }

    /**
     * Sum() and Sum(x => ...): 0 for no elements; nulls are skipped. A sum of ints or longs is
     * checked, as in .NET: OverflowException past the type's range.
     */
    public function Sum(?\Closure $selector = null): int|float
    {
        return Enumerable::Sum($this->sequence(), $selector);
    }

    /**
     * Average() and Average(x => ...): the mean as a double, nulls skipped. No values: InvalidOperationException
     * for a non-nullable type (int, double, ...), null for int?, double?, ..., as in .NET.
     */
    public function Average(?\Closure $selector = null): ?float
    {
        return Enumerable::Average($this->sequence(), $selector);
    }

    /**
     * Min() and Min(x => ...): the smallest value in Comparer<T>.Default's order; nulls are skipped. With no
     * values: InvalidOperationException for a non-nullable value type (int, double, ...), else null, as in .NET.
     */
    public function Min(?\Closure $selector = null): mixed
    {
        return Enumerable::Min($this->sequence(), $selector);
    }

    /**
     * Max() and Max(x => ...): the largest value in Comparer<T>.Default's order; nulls are skipped. With no
     * values: InvalidOperationException for a non-nullable value type (int, double, ...), else null, as in .NET.
     */
    public function Max(?\Closure $selector = null): mixed
    {
        return Enumerable::Max($this->sequence(), $selector);
    }

    /** @return IOrderedEnumerable<T> */
    public function OrderBy(?\Closure $keySelector = null): IOrderedEnumerable
    {
        return Enumerable::OrderBy($this, $keySelector);
    }

    /** @return IOrderedEnumerable<T> */
    public function OrderByDescending(?\Closure $keySelector = null): IOrderedEnumerable
    {
        return Enumerable::OrderByDescending($this, $keySelector);
    }

    /**
     * @template TSecond
     * @template TResult
     * @param iterable<TSecond> $second
     * @param \Closure(T, TSecond): TResult $resultSelector
     * @return iterable<TResult>
     */
    public function Zip(iterable $second, \Closure $resultSelector): iterable
    {
        return Enumerable::Zip($this, $second, $resultSelector);
    }

    /** @return iterable<T> */
    public function Take(int $count): iterable
    {
        return Enumerable::Take($this, $count);
    }

    /** @return iterable<T> */
    public function Skip(int $count): iterable
    {
        return Enumerable::Skip($this, $count);
    }

    /** @return iterable<T> */
    public function TakeWhile(\Closure $predicate): iterable
    {
        return Enumerable::TakeWhile($this, $predicate);
    }

    /** @return iterable<T> */
    public function SkipWhile(\Closure $predicate): iterable
    {
        return Enumerable::SkipWhile($this, $predicate);
    }

    /** @return iterable<T> */
    public function Distinct(): iterable
    {
        return Enumerable::Distinct($this);
    }

    /**
     * @param iterable<T> $second
     * @return iterable<T>
     */
    public function Concat(iterable $second): iterable
    {
        return Enumerable::Concat($this, $second);
    }

    /** @return List_<T> */
    public function ToList(): List_
    {
        return Enumerable::ToList($this->sequence());
    }

    /** @return T[] */
    public function ToArray(): array
    {
        return Enumerable::ToArray($this->sequence());
    }

    /** @phpstan-return Dictionary<mixed, mixed> */
    public function ToDictionary(\Closure $keySelector, ?\Closure $elementSelector = null): Dictionary
    {
        return Enumerable::ToDictionary($this->sequence(), $keySelector, $elementSelector);
    }

    /** @return iterable<\SharpPhp\Runtime\System\Linq\Grouping> */
    public function GroupBy(\Closure $keySelector): iterable
    {
        return Enumerable::GroupBy($this, $keySelector);
    }

    /**
     * Aggregate((acc, x) => ...) folds from the first element (InvalidOperationException for no elements);
     * Aggregate(seed, (acc, x) => ...) from the seed, which an empty sequence returns as it is.
     */
    public function Aggregate(mixed $seedOrFunc, ?\Closure $func = null): mixed
    {
        return Enumerable::Aggregate($this->sequence(), $seedOrFunc, $func);
    }

    /** @param iterable<mixed> $other */
    public function SequenceEqual(iterable $other): bool
    {
        return Enumerable::SequenceEqual($this->sequence(), $other);
    }
}
