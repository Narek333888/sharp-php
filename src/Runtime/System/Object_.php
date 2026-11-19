<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Object static members.
 */
final class Object_
{
    public static function ReferenceEquals(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public static function Equals(mixed $a, mixed $b): bool
    {
        return Op::equals($a, $b);
    }
}
