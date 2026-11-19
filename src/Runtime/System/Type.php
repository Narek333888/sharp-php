<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\TypeName;

/**
 * System.Type, as returned by typeof(T) and GetType().
 *
 * There is one instance per type name, so typeof(A) == x.GetType() compares by identity.
 * Generic arguments are erased at runtime: typeof(List<int>) is "System.Collections.Generic.List".
 * Values only know their PHP type: 5L.GetType() is System.Int32 (typeof(long) is System.Int64).
 */
final class Type
{
    /** @var array<string, self> */
    private static array $types = [];

    /** "App.Models.Person" */
    public string $Name {
        get => substr((string) strrchr('.' . $this->FullName, '.'), 1);
    }

    /** "App.Models", or null for the global namespace */
    public ?string $Namespace {
        get => str_contains($this->FullName, '.') ? substr($this->FullName, 0, (int) strrpos($this->FullName, '.')) : null;
    }

    private function __construct(public readonly string $FullName)
    {
    }

    /** typeof(Person): the C# type of a PHP class. */
    public static function ofClass(string $class): self
    {
        return self::named(TypeName::ofClass($class));
    }

    /** typeof(long), typeof(int[]): a type without a PHP class, by its .NET name ("System.Int64"). */
    public static function named(string $fullName): self
    {
        return self::$types[$fullName] ??= new self($fullName);
    }

    /** value.GetType() */
    public static function fromValue(mixed $value): self
    {
        if ($value === null) {
            throw new NullReferenceException();
        }

        return self::named(TypeName::of($value));
    }

    public function Equals(mixed $other): bool
    {
        return $other === $this;
    }

    public function GetHashCode(): int
    {
        return crc32($this->FullName);
    }

    public function ToString(): string
    {
        return $this->FullName;
    }

    public function __toString(): string
    {
        return $this->FullName;
    }
}
