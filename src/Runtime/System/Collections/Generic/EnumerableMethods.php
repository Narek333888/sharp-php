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
    /** @return List_<T> */
    public function Where(\Closure $predicate): List_
    {
        return Enumerable::Where($this, $predicate);
    }

    /** @return T|null */
    public function MinBy(\Closure $keySelector): mixed
    {
        return Enumerable::MinBy($this, $keySelector);
    }

    /** @return T|null */
    public function MaxBy(\Closure $keySelector): mixed
    {
        return Enumerable::MaxBy($this, $keySelector);
    }

    /** @return List_<T> */
    public function DistinctBy(\Closure $keySelector): List_
    {
        return Enumerable::DistinctBy($this, $keySelector);
    }

    /** @return List_<T[]> */
    public function Chunk(int $size): List_
    {
        return Enumerable::Chunk($this, $size);
    }

    /** @return List_<mixed> */
    public function Select(\Closure $selector): List_
    {
        return Enumerable::Select($this, $selector);
    }

    /** @return List_<mixed> */
    public function SelectMany(\Closure $selector): List_
    {
        return Enumerable::SelectMany($this, $selector);
    }

    public function Any(?\Closure $predicate = null): bool
    {
        return Enumerable::Any($this, $predicate);
    }

    public function All(\Closure $predicate): bool
    {
        return Enumerable::All($this, $predicate);
    }

    // No Count() here: PHP method names ignore case, so it would clash with Countable's count();
    // each collection declares Count(?Closure $predicate = null), which is both.

    /** @return T */
    public function First(?\Closure $predicate = null): mixed
    {
        return Enumerable::First($this, $predicate);
    }

    /** @return T|null */
    public function FirstOrDefault(?\Closure $predicate = null, mixed $defaultValue = null): mixed
    {
        return Enumerable::FirstOrDefault($this, $predicate, $defaultValue);
    }

    /** @return T */
    public function Last(?\Closure $predicate = null): mixed
    {
        return Enumerable::Last($this, $predicate);
    }

    /** @return T|null */
    public function LastOrDefault(?\Closure $predicate = null): mixed
    {
        return Enumerable::LastOrDefault($this, $predicate);
    }

    /** @return T */
    public function Single(?\Closure $predicate = null): mixed
    {
        return Enumerable::Single($this, $predicate);
    }

    /** @return T */
    public function ElementAt(int $index): mixed
    {
        return Enumerable::ElementAt($this, $index);
    }

    public function Sum(?\Closure $selector = null): int|float
    {
        return Enumerable::Sum($this, $selector);
    }

    public function Average(?\Closure $selector = null): float
    {
        return Enumerable::Average($this, $selector);
    }

    public function Min(?\Closure $selector = null): mixed
    {
        return Enumerable::Min($this, $selector);
    }

    public function Max(?\Closure $selector = null): mixed
    {
        return Enumerable::Max($this, $selector);
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
     * @return List_<TResult>
     */
    public function Zip(iterable $second, \Closure $resultSelector): List_
    {
        return Enumerable::Zip($this, $second, $resultSelector);
    }

    /** @return List_<T> */
    public function Take(int $count): List_
    {
        return Enumerable::Take($this, $count);
    }

    /** @return List_<T> */
    public function Skip(int $count): List_
    {
        return Enumerable::Skip($this, $count);
    }

    /** @return List_<T> */
    public function TakeWhile(\Closure $predicate): List_
    {
        return Enumerable::TakeWhile($this, $predicate);
    }

    /** @return List_<T> */
    public function SkipWhile(\Closure $predicate): List_
    {
        return Enumerable::SkipWhile($this, $predicate);
    }

    /** @return List_<T> */
    public function Distinct(): List_
    {
        return Enumerable::Distinct($this);
    }

    /**
     * @param iterable<T> $second
     * @return List_<T>
     */
    public function Concat(iterable $second): List_
    {
        return Enumerable::Concat($this, $second);
    }

    /** @return List_<T> */
    public function ToList(): List_
    {
        return Enumerable::ToList($this);
    }

    /** @return T[] */
    public function ToArray(): array
    {
        return Enumerable::ToArray($this);
    }

    /** @phpstan-return Dictionary<mixed, mixed> */
    public function ToDictionary(\Closure $keySelector, ?\Closure $elementSelector = null): Dictionary
    {
        return Enumerable::ToDictionary($this, $keySelector, $elementSelector);
    }

    /** @return List_<\SharpPhp\Runtime\System\Linq\Grouping> */
    public function GroupBy(\Closure $keySelector): List_
    {
        return Enumerable::GroupBy($this, $keySelector);
    }

    public function Aggregate(mixed $seedOrFunc, ?\Closure $func = null): mixed
    {
        return Enumerable::Aggregate($this, $seedOrFunc, $func);
    }

    /** @param iterable<mixed> $other */
    public function SequenceEqual(iterable $other): bool
    {
        return Enumerable::SequenceEqual($this, $other);
    }
}
