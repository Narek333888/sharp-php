<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\Linq\Enumerable;
use SharpPhp\Runtime\System\NullReferenceException;
use SharpPhp\Runtime\System\String_;

/**
 * Late-bound member access, used when the compiler cannot know a receiver's type
 * (object, dynamic, unconstrained generics). Strings and arrays get their C# members.
 */
final class Dyn
{
    /**
     * receiver.Name when the receiver's type is not known: the Length of a string (in characters) or an array, the
     * Count of an array or a Countable, an exception's Message, or the object's property (also through __get). Null
     * throws NullReferenceException, an unknown member InvalidOperationException ("'X' does not contain a definition
     * for 'Name'").
     */
    public static function get(mixed $target, string $name): mixed
    {
        if ($target === null) {
            throw new NullReferenceException();
        }
        if (is_string($target) && $name === 'Length') {
            return mb_strlen($target);
        }
        if (is_array($target) && ($name === 'Length' || $name === 'Count')) {
            return count($target);
        }
        if ($target instanceof \Throwable && $name === 'Message') {
            return $target->getMessage();
        }
        if (is_object($target)) {
            if (property_exists($target, $name) || method_exists($target, '__get')) {
                return $target->$name;
            }
            if ($name === 'Count' && $target instanceof \Countable) {
                return count($target);
            }
        }

        throw new System\InvalidOperationException(sprintf("'%s' does not contain a definition for '%s'", TypeName::of($target), $name));
    }

    /**
     * receiver.Name(args) when the receiver's type is not known: the object's own method, else a string method of
     * String_ ("abc".ToUpper()), a LINQ method of Enumerable on a sequence, or System.Object's ToString, Equals,
     * CompareTo and GetHashCode for any value. Null throws NullReferenceException, an unknown method
     * InvalidOperationException.
     */
    public static function call(mixed $target, string $name, mixed ...$args): mixed
    {
        if ($target === null) {
            throw new NullReferenceException();
        }
        if (is_object($target) && method_exists($target, $name)) {
            return $target->$name(...$args);
        }
        if (is_string($target) && method_exists(String_::class, $name)) {
            return String_::$name($target, ...$args);
        }
        if (is_iterable($target) && method_exists(Enumerable::class, $name)) {
            return Enumerable::$name($target, ...$args);
        }

        return match ($name) {
            'ToString' => $args === [] ? Op::str($target) : Op::format($target, Op::str($args[0])),
            'Equals' => Op::equals($target, $args[0] ?? null),
            'CompareTo' => $target <=> ($args[0] ?? null),
            'GetHashCode' => Op::hashCode($target),
            default => throw new System\InvalidOperationException(sprintf("'%s' does not contain a definition for '%s'", TypeName::of($target), $name)),
        };
    }
}
