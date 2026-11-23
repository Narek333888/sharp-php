<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * An object of an anonymous type: new { Id = 1, Name = "Ann" }.
 * The compiler checks property names and types and keeps the properties read-only; at run time
 * they are public properties, so json_encode() and get_object_vars() see them.
 * Like C#, two anonymous objects are Equal when all their properties are, and ToString()
 * prints "{ Id = 1, Name = Ann }". As JSON it is an object with its properties.
 */
#[\AllowDynamicProperties]
final class AnonymousObject implements \JsonSerializable
{
    /**
     * The C# types of the objects' double, float and decimal properties (which a PHP float does not
     * know), beside the objects so that they are not properties.
     *
     * @var \WeakMap<self, array<string, 'double'|'float'|'decimal'>>|null
     */
    private static ?\WeakMap $numberTypes = null;

    /**
     * @param array<string, mixed> $properties
     * @param array<string, 'double'|'float'|'decimal'> $numberTypes the compiler's types of the number properties
     */
    public function __construct(array $properties = [], array $numberTypes = [])
    {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
        if ($numberTypes !== []) {
            self::$numberTypes ??= new \WeakMap();
            self::$numberTypes[$this] = $numberTypes;
        }
    }

    public function ToString(): string
    {
        $types = self::$numberTypes[$this] ?? [];
        $parts = [];
        foreach (get_object_vars($this) as $name => $value) {
            $type = $types[$name] ?? null;
            $parts[] = $name . ' = ' . ($type !== null && (is_int($value) || is_float($value)) ? (new TypedNumber($value, $type))->text() : Op::str($value));
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

    public function jsonSerialize(): \stdClass
    {
        return (object) get_object_vars($this);
    }

    public function GetHashCode(): int
    {
        return crc32(serialize(array_map(static fn (mixed $v): mixed => is_object($v) ? spl_object_id($v) : $v, get_object_vars($this))));
    }
}
