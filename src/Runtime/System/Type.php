<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\TypeName;

/**
 * System.Type, as returned by typeof(T) and GetType().
 *
 * There is one instance per type name, so typeof(A) == x.GetType() compares by identity.
 * Generic arguments are erased at runtime: typeof(List<int>) is "System.Collections.Generic.List".
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

    /** typeof(T): a PHP class name or a primitive spec ("int", "string", ...). */
    public static function of(string $classOrSpec): self
    {
        $known = class_exists($classOrSpec) || interface_exists($classOrSpec) || enum_exists($classOrSpec);

        return self::named($known ? TypeName::ofClass($classOrSpec) : TypeName::ofSpec($classOrSpec));
    }

    /** value.GetType() */
    public static function fromValue(mixed $value): self
    {
        if ($value === null) {
            throw new NullReferenceException();
        }

        return self::named(TypeName::of($value));
    }

    private static function named(string $fullName): self
    {
        return self::$types[$fullName] ??= new self($fullName);
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
