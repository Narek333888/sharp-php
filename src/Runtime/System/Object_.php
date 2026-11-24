<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Object static members.
 */
final class Object_
{
    /**
     * object.ReferenceEquals(a, b): for objects, whether they are the same instance. Other values compare with PHP's ===,
     * by value, so unlike .NET (which boxes each 1) ReferenceEquals(1, 1) is true.
     */
    public static function ReferenceEquals(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    /**
     * object.Equals(a, b): a.Equals(b) when a is an object with an Equals method; numbers by value, also across int and
     * double (1 equals 1.0, unlike .NET); anything else with PHP's ===, so two nulls are equal.
     */
    public static function Equals(mixed $a, mixed $b): bool
    {
        return Op::equals($a, $b);
    }
}
