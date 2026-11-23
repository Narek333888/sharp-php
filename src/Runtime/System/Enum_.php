<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Enum: the names and values of an enum type, and parsing names.
 *
 *   Enum.GetNames(typeof(Color))          Enum.GetValues<Color>()
 *   Enum.Parse<Color>("Green")            Enum.TryParse<Color>("Blue", out var color)
 *   Enum.IsDefined(typeof(Color), 2)      Enum.GetName(typeof(Color), Color.Red)
 *
 * The generic forms get the enum's class from the compiler (generic arguments are erased in PHP).
 */
final class Enum_
{
    /** @return list<string> */
    public static function GetNames(?Type $enumType = null, ?string $enumClass = null): array
    {
        return array_map(static fn (\UnitEnum $case): string => $case->name, self::cases($enumType, $enumClass));
    }

    /**
     * @sharp-template TEnum
     * @sharp-reified TEnum $enumClass
     * @sharp-return TEnum[]
     * @return list<\UnitEnum>
     */
    public static function GetValues(?Type $enumType = null, ?string $enumClass = null): array
    {
        return self::cases($enumType, $enumClass);
    }

    /** The name of a value of the enum, or null when no member has it. */
    public static function GetName(Type $enumType, mixed $value): ?string
    {
        return self::find(self::cases($enumType, null), $value)?->name;
    }

    /** Whether the enum has a member with that value (a number, a member, or a name). */
    public static function IsDefined(Type $enumType, mixed $value): bool
    {
        return self::find(self::cases($enumType, null), $value) !== null;
    }

    /**
     * Enum.Parse<Color>("Green") (also "green" with ignoreCase, or "1"); Enum.Parse(typeof(Color), "Green").
     * ArgumentException when no member matches.
     *
     * @sharp-template TEnum
     * @sharp-reified TEnum $enumClass
     * @param Type|string $enumTypeOrValue typeof(TEnum) and then the text, or the text of the generic form
     * @param string|bool $valueOrIgnoreCase
     * @sharp-return TEnum
     */
    public static function Parse(Type|string $enumTypeOrValue, string|bool $valueOrIgnoreCase = false, bool $ignoreCase = false, ?string $enumClass = null): \UnitEnum
    {
        if ($enumTypeOrValue instanceof Type) {
            [$type, $text, $ignore] = [$enumTypeOrValue, (string) $valueOrIgnoreCase, $ignoreCase];
        } else {
            [$type, $text, $ignore] = [null, $enumTypeOrValue, (bool) $valueOrIgnoreCase];
        }
        $found = self::match(self::cases($type, $enumClass), $text, $ignore);
        if ($found === null) {
            throw new ArgumentException("Requested value '$text' was not found.");
        }

        return $found;
    }

    /**
     * Enum.TryParse<Color>("Green", out var color): false (and the default member) when no member matches.
     *
     * @sharp-template TEnum
     * @sharp-reified TEnum $enumClass
     * @sharp-param-out TEnum $result
     * @param-out \UnitEnum|null $result
     */
    public static function TryParse(string $value, mixed &$result, ?string $enumClass = null): bool
    {
        $cases = self::cases(null, $enumClass);
        $found = self::match($cases, $value, false);
        $result = $found ?? self::find($cases, 0) ?? ($cases[0] ?? null);

        return $found !== null;
    }

    /** @return list<\UnitEnum> */
    private static function cases(?Type $enumType, ?string $enumClass): array
    {
        $class = $enumClass ?? $enumType?->phpClass();
        if ($class === null || !enum_exists($class)) {
            throw new ArgumentException('Type provided must be an Enum.' . ($enumType !== null ? " ({$enumType->FullName})" : ' (pass the enum type: Enum.GetValues<Color>())'));
        }

        return $class::cases();
    }

    /** @param list<\UnitEnum> $cases */
    private static function match(array $cases, string $text, bool $ignoreCase): ?\UnitEnum
    {
        $text = trim($text);
        if (preg_match('/^[+-]?\d+$/', $text) === 1) {
            return self::find($cases, (int) $text);
        }
        foreach ($cases as $case) {
            if ($ignoreCase ? strcasecmp($case->name, $text) === 0 : $case->name === $text) {
                return $case;
            }
        }

        return null;
    }

    /** @param list<\UnitEnum> $cases */
    private static function find(array $cases, mixed $value): ?\UnitEnum
    {
        foreach ($cases as $case) {
            if ($case === $value || (is_string($value) && $case->name === $value)
                || (is_int($value) && $case instanceof \BackedEnum && $case->value === $value)) {
                return $case;
            }
        }

        return null;
    }
}
