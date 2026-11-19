<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * An object of an anonymous type: new { Id = 1, Name = "Ann" }.
 * The compiler checks property names and types and keeps the properties read-only; at run time
 * they are public properties, so json_encode() and get_object_vars() see them.
 * Like C#, two anonymous objects are Equal when all their properties are, and ToString()
 * prints "{ Id = 1, Name = Ann }".
 */
#[\AllowDynamicProperties]
final class AnonymousObject
{
    /** @param array<string, mixed> $properties */
    public function __construct(array $properties = [])
    {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }

    public function ToString(): string
    {
        $parts = [];
        foreach (get_object_vars($this) as $name => $value) {
            $parts[] = $name . ' = ' . Op::str($value);
        }

        return $parts === [] ? '{ }' : '{ ' . implode(', ', $parts) . ' }';
    }

    public function Equals(mixed $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        $mine = get_object_vars($this);
        $theirs = get_object_vars($other);
        if (array_keys($mine) !== array_keys($theirs)) {
            return false;
        }
        foreach ($mine as $name => $value) {
            if (!Op::equals($value, $theirs[$name])) {
                return false;
            }
        }

        return true;
    }

    public function GetHashCode(): int
    {
        return crc32(serialize(array_map(static fn (mixed $v): mixed => is_object($v) ? spl_object_id($v) : $v, get_object_vars($this))));
    }
}
