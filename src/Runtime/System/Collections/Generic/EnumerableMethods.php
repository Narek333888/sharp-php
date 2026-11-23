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
     * walk than an iterator and needs no copy (PHP copies arrays only when one is changed).
     *
     * @internal
     * @return iterable<T>
     */
    public function sequence(): iterable
    {
        return $this;
    }

    /** @return List_<T> */
    public function Where(\Closure $predicate): List_
    {
        return Enumerable::Where($this->sequence(), $predicate);
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

    /** @return List_<T> */
    public function DistinctBy(\Closure $keySelector): List_
    {
        return Enumerable::DistinctBy($this->sequence(), $keySelector);
    }

    /** @return List_<T[]> */
    public function Chunk(int $size): List_
    {
        return Enumerable::Chunk($this->sequence(), $size);
    }

    /** @return List_<mixed> */
    public function Select(\Closure $selector): List_
    {
        return Enumerable::Select($this->sequence(), $selector);
    }

    /** @return List_<mixed> */
    public function SelectMany(\Closure $selector): List_
    {
        return Enumerable::SelectMany($this->sequence(), $selector);
    }

    public function Any(?\Closure $predicate = null): bool
    {
        return Enumerable::Any($this->sequence(), $predicate);
    }

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

    public function Sum(?\Closure $selector = null): int|float
    {
        return Enumerable::Sum($this->sequence(), $selector);
    }

    public function Average(?\Closure $selector = null): float
    {
        return Enumerable::Average($this->sequence(), $selector);
    }

    public function Min(?\Closure $selector = null): mixed
    {
        return Enumerable::Min($this->sequence(), $selector);
    }

    public function Max(?\Closure $selector = null): mixed
    {
        return Enumerable::Max($this->sequence(), $selector);
    }

    /** @return IOrderedEnumerable<T> */
    public function OrderBy(?\Closure $keySelector = null): IOrderedEnumerable
    {
        return Enumerable::OrderBy($this->sequence(), $keySelector);
    }

    /** @return IOrderedEnumerable<T> */
    public function OrderByDescending(?\Closure $keySelector = null): IOrderedEnumerable
    {
        return Enumerable::OrderByDescending($this->sequence(), $keySelector);
    }

    /**
     * @template TSecond
     * @template TResult
     * @param iterable<TSecond> $second
     * @param \Closure(T, TSecond): TResult $resultSelector
     * @return List_<TResult>
     */
    public function Zip(iterable $second, \Closure $resultSelector): List_
    {
        return Enumerable::Zip($this->sequence(), $second, $resultSelector);
    }

    /** @return List_<T> */
    public function Take(int $count): List_
    {
        return Enumerable::Take($this->sequence(), $count);
    }

    /** @return List_<T> */
    public function Skip(int $count): List_
    {
        return Enumerable::Skip($this->sequence(), $count);
    }

    /** @return List_<T> */
    public function TakeWhile(\Closure $predicate): List_
    {
        return Enumerable::TakeWhile($this->sequence(), $predicate);
    }

    /** @return List_<T> */
    public function SkipWhile(\Closure $predicate): List_
    {
        return Enumerable::SkipWhile($this->sequence(), $predicate);
    }

    /** @return List_<T> */
    public function Distinct(): List_
    {
        return Enumerable::Distinct($this->sequence());
    }

    /**
     * @param iterable<T> $second
     * @return List_<T>
     */
    public function Concat(iterable $second): List_
    {
        return Enumerable::Concat($this->sequence(), $second);
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

    /** @return List_<\SharpPhp\Runtime\System\Linq\Grouping> */
    public function GroupBy(\Closure $keySelector): List_
    {
        return Enumerable::GroupBy($this->sequence(), $keySelector);
    }

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
