<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Array static helpers. Arrays are passed by reference where C# mutates them in place.
 */
final class Array_
{
    /** @param array<mixed> $array */
    public static function Sort(array &$array, ?\Closure $comparison = null): void
    {
        usort($array, $comparison ?? Op::compare(...));
    }

    /** @param array<mixed> $array */
    public static function Reverse(array &$array): void
    {
        $array = array_reverse($array);
    }

    /** @param array<mixed> $array */
    public static function IndexOf(array $array, mixed $value): int
    {
        foreach ($array as $i => $item) {
            if (Op::equals($item, $value)) {
                return $i;
            }
        }

        return -1;
    }

    /** @param array<mixed> $array */
    public static function Exists(array $array, \Closure $match): bool
    {
        foreach ($array as $item) {
            if ($match($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $array */
    public static function Find(array $array, \Closure $match): mixed
    {
        foreach ($array as $item) {
            if ($match($item)) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<mixed> $array */
    public static function Fill(array &$array, mixed $value): void
    {
        $array = array_fill(0, count($array), $value);
    }

    /**
     * @param array<mixed>|null $array
     * @param-out list<mixed> $array
     */
    public static function Resize(?array &$array, int $newSize): void
    {
        $array ??= [];
        $array = array_values(count($array) >= $newSize ? array_slice($array, 0, $newSize) : array_pad($array, $newSize, null));
    }

    /** @return mixed[] */
    public static function Empty(): array
    {
        return [];
    }
}
