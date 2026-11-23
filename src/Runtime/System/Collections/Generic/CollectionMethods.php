<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\NotSupportedException;

/**
 * The members of ICollection<T> and IList<T> for a value of those types: a List, HashSet or Dictionary
 * (their own methods), an array (fixed size, as a C# array behind an IList: Add and Remove throw
 * NotSupportedException) or a PHP collection.
 *
 *   ICollection<Order> orders = new List<Order>();   orders.Add(order)  =>  CollectionMethods::Add($orders, $order)
 *
 * @internal
 */
final class CollectionMethods
{
    /**
     * @template T
     * @param iterable<T> $collection
     * @param T $item
     */
    public static function Add(iterable $collection, mixed $item): void
    {
        self::call($collection, 'Add', $item);
    }

    /**
     * @template T
     * @param iterable<T> $collection
     * @param T $item
     */
    public static function Remove(iterable $collection, mixed $item): bool
    {
        return (bool) self::call($collection, 'Remove', $item);
    }

    /**
     * @template T
     * @param iterable<T> $collection
     */
    public static function Clear(iterable $collection): void
    {
        self::call($collection, 'Clear');
    }

    /**
     * @template T
     * @param iterable<T> $collection
     * @param T $item
     */
    public static function Contains(iterable $collection, mixed $item): bool
    {
        if (is_object($collection) && method_exists($collection, 'Contains')) {
            return (bool) $collection->Contains($item);
        }
        foreach ($collection as $element) {
            if (Op::equals($element, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T
     * @param iterable<T> $list
     * @param T $item
     */
    public static function IndexOf(iterable $list, mixed $item): int
    {
        if (is_object($list) && method_exists($list, 'IndexOf')) {
            $index = $list->IndexOf($item);

            return is_int($index) ? $index : -1;
        }
        $i = 0;
        foreach ($list as $element) {
            if (Op::equals($element, $item)) {
                return $i;
            }
            $i++;
        }

        return -1;
    }

    /**
     * @template T
     * @param iterable<T> $list
     * @param T $item
     */
    public static function Insert(iterable $list, int $index, mixed $item): void
    {
        self::call($list, 'Insert', $index, $item);
    }

    /**
     * @template T
     * @param iterable<T> $list
     */
    public static function RemoveAt(iterable $list, int $index): void
    {
        self::call($list, 'RemoveAt', $index);
    }

    /**
     * Calls the collection's $method; an array has a fixed size.
     *
     * @param iterable<mixed> $collection
     */
    private static function call(iterable $collection, string $method, mixed ...$args): mixed
    {
        if (is_object($collection) && method_exists($collection, $method)) {
            return $collection->{$method}(...$args);
        }

        throw new NotSupportedException(is_array($collection) ? 'Collection was of a fixed size.' : "Specified method is not supported: '$method'.");
    }
}
