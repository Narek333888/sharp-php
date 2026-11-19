<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Array static helpers. Arrays are passed by reference where C# mutates them in place.
 */
final class Array_
{
    public static function Sort(array &$array, ?\Closure $comparison = null): void
    {
        usort($array, $comparison ?? Op::compare(...));
    }

    public static function Reverse(array &$array): void
    {
        $array = array_reverse($array);
    }

    public static function IndexOf(array $array, mixed $value): int
    {
        foreach ($array as $i => $item) {
            if (Op::equals($item, $value)) {
                return $i;
            }
        }

        return -1;
    }

    public static function Exists(array $array, \Closure $match): bool
    {
        foreach ($array as $item) {
            if ($match($item)) {
                return true;
            }
        }

        return false;
    }

    public static function Find(array $array, \Closure $match): mixed
    {
        foreach ($array as $item) {
            if ($match($item)) {
                return $item;
            }
        }

        return null;
    }

    public static function Fill(array &$array, mixed $value): void
    {
        $array = array_fill(0, count($array), $value);
    }

    public static function Resize(?array &$array, int $newSize): void
    {
        $array ??= [];
        $array = count($array) >= $newSize ? array_slice($array, 0, $newSize) : array_pad($array, $newSize, null);
    }

    /** @return mixed[] */
    public static function Empty(): array
    {
        return [];
    }
}
