<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\DivideByZeroException;
use SharpPhp\Runtime\System\FormatException;
use SharpPhp\Runtime\System\InvalidCastException;

/**
 * Operators whose C# semantics depend on runtime types, plus helpers used by generated code.
 */
final class Op
{
    /** C# "+": string concatenation when either side is a string, numeric addition otherwise. */
    public static function add(mixed $a, mixed $b): mixed
    {
        if (is_string($a) || is_string($b)) {
            return self::str($a) . self::str($b);
        }
        if ($a === null || $b === null) {
            return null;
        }

        return $a + $b;
    }

    /** C# "/": integer division for two integers. */
    public static function div(mixed $a, mixed $b): int|float|null
    {
        if ($a === null || $b === null) {
            return null;
        }
        if (is_int($a) && is_int($b)) {
            if ($b === 0) {
                throw new DivideByZeroException();
            }

            return intdiv($a, $b);
        }
        if ($b == 0) {
            return is_nan((float) $a) || $a == 0 ? NAN : ($a > 0 ? INF : -INF);
        }

        return $a / $b;
    }

    public static function mod(mixed $a, mixed $b): int|float|null
    {
        if ($a === null || $b === null) {
            return null;
        }
        if (is_int($a) && is_int($b)) {
            if ($b === 0) {
                throw new DivideByZeroException();
            }

            return $a % $b;
        }

        return fmod((float) $a, (float) $b);
    }

    /** Converts any value to a string the way C# does (ToString()). */
    public static function str(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === null => '',
            is_bool($value) => $value ? 'True' : 'False',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            $value instanceof \UnitEnum => $value->name,
            is_object($value) && method_exists($value, 'ToString') => (string) $value->ToString(),
            $value instanceof \Throwable => TypeName::of($value) . ': ' . $value->getMessage(),
            $value instanceof \Stringable => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('n/j/Y g:i:s A'),
            default => TypeName::of($value),
        };
    }

    private static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '∞' : '-∞';
        }
        // PHP prints 1.0E+20 where C# prints 1E+20.
        return preg_replace('/\.0E/', 'E', (string) $value) ?? (string) $value;
    }

    /** Standard and custom C# format strings: F2, N0, D5, X, P1, E3, 0.00, yyyy-MM-dd ... */
    public static function format(mixed $value, string $format): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::dateFormat($format));
        }
        if (!is_int($value) && !is_float($value)) {
            return self::str($value);
        }

        if (preg_match('/^([A-Za-z])(\d{0,2})$/', $format, $m)) {
            $kind = strtoupper($m[1]);
            $digits = $m[2] === '' ? null : (int) $m[2];

            return match ($kind) {
                'F' => number_format((float) $value, $digits ?? 2, '.', ''),
                'N' => number_format((float) $value, $digits ?? 2, '.', ','),
                'D' => is_int($value)
                    ? ($value < 0 ? '-' : '') . str_pad((string) abs($value), $digits ?? 0, '0', STR_PAD_LEFT)
                    : throw new FormatException('Format specifier was invalid.'),
                'X' => is_int($value)
                    ? str_pad($m[1] === 'x' ? dechex($value) : strtoupper(dechex($value)), $digits ?? 0, '0', STR_PAD_LEFT)
                    : throw new FormatException('Format specifier was invalid.'),
                'P' => number_format((float) $value * 100, $digits ?? 2, '.', ',') . ' %',
                'E' => self::exponential((float) $value, $digits ?? 6, $m[1] === 'e'),
                'C' => ($value < 0 ? '-' : '') . '$' . number_format(abs((float) $value), $digits ?? 2, '.', ','),
                'G' => self::str($value),
                default => throw new FormatException('Format specifier was invalid.'),
            };
        }

        // Custom numeric format: 0.00, #,##0.0, 000
        if (preg_match('/^[#0,.]+$/', $format)) {
            $parts = explode('.', $format, 2);
            $decimals = isset($parts[1]) ? strlen($parts[1]) : 0;
            $minDecimals = isset($parts[1]) ? strlen(rtrim($parts[1], '#')) : 0;
            $text = number_format((float) $value, $decimals, '.', str_contains($parts[0], ',') ? ',' : '');
            if ($decimals > $minDecimals && str_contains($text, '.')) {
                [$int, $frac] = explode('.', $text);
                $frac = rtrim($frac, '0');
                $frac = str_pad($frac, $minDecimals, '0');
                $text = $frac === '' ? $int : $int . '.' . $frac;
            }
            $minInt = strlen(str_replace([',', '#'], '', $parts[0]));
            [$int] = explode('.', $text);
            $sign = str_starts_with($int, '-') ? '-' : '';
            $digitsOnly = ltrim($int, '-');
            if (strlen(str_replace(',', '', $digitsOnly)) < $minInt) {
                $text = $sign . str_pad($digitsOnly, $minInt, '0', STR_PAD_LEFT) . substr($text, strlen($int));
            }

            return $text;
        }

        return self::str($value);
    }

    private static function exponential(float $value, int $digits, bool $lower): string
    {
        $text = sprintf('%.' . $digits . 'E', $value);
        $text = preg_replace_callback('/E([+-])(\d+)/', static fn (array $m): string => 'E' . $m[1] . str_pad($m[2], 3, '0', STR_PAD_LEFT), $text) ?? $text;

        return $lower ? strtolower($text) : $text;
    }

    private static function dateFormat(string $format): string
    {
        $map = [
            'yyyy' => 'Y', 'yy' => 'y', 'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n', 'dddd' => 'l',
            'ddd' => 'D', 'dd' => 'd', 'd' => 'j', 'HH' => 'H', 'H' => 'G', 'hh' => 'h', 'h' => 'g', 'mm' => 'i',
            'ss' => 's', 'fff' => 'v', 'tt' => 'A',
        ];

        return preg_replace_callback(
            '/yyyy|yy|MMMM|MMM|MM|M|dddd|ddd|dd|d|HH|H|hh|h|mm|ss|fff|tt|[A-Za-z]/',
            static fn (array $m): string => $map[$m[0]] ?? '\\' . $m[0],
            $format,
        ) ?? $format;
    }

    /** Default object.Equals semantics. */
    public static function equals(mixed $a, mixed $b): bool
    {
        if (is_object($a) && method_exists($a, 'Equals')) {
            return (bool) $a->Equals($b);
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    /**
     * Default ordering (Comparer<T>.Default): IComparable objects use their CompareTo,
     * everything else PHP's <=>. Returns -1, 0 or 1.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        if (is_object($a) && method_exists($a, 'CompareTo')) {
            return $a->CompareTo($b) <=> 0;
        }
        if (is_object($b) && method_exists($b, 'CompareTo')) {
            return -($b->CompareTo($a) <=> 0);
        }

        return $a <=> $b;
    }

    /**
     * "record with { Name = value }": copies every stored property (bypassing the constructor,
     * like C#'s copy constructor) and applies the changes. Readonly properties are initialized
     * from their declaring class's scope.
     *
     * @template T of object
     * @param T $source
     * @param array<string, mixed> $changes
     * @return T
     */
    public static function with(?object $source, array $changes): object
    {
        if ($source === null) {
            throw new System\NullReferenceException();
        }
        $class = new \ReflectionClass($source);
        $copy = $class->newInstanceWithoutConstructor();
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $values = [];
            foreach ($current->getProperties() as $property) {
                if ($property->isStatic() || $property->isVirtual() || $property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }
                $name = $property->getName();
                if (array_key_exists($name, $changes)) {
                    $values[$name] = $changes[$name];
                } elseif ($property->isInitialized($source)) {
                    $values[$name] = $property->getValue($source);
                }
            }
            (\Closure::bind(static function () use ($copy, $values): void {
                foreach ($values as $name => $value) {
                    $copy->$name = $value;
                }
            }, null, $current->getName()))();
        }

        return $copy;
    }

    /** "x as T" */
    public static function as(mixed $value, string $type): mixed
    {
        return TypeCheck::matches($value, $type) && $value !== null ? $value : null;
    }

    /** "(T)x" for reference types: throws InvalidCastException like C#. */
    public static function cast(mixed $value, string $type): mixed
    {
        if ($value === null || TypeCheck::matches($value, $type)) {
            return TypeCheck::coerce($value, $type);
        }

        throw new InvalidCastException(sprintf(
            "Unable to cast object of type '%s' to type '%s'.",
            TypeName::of($value),
            TypeName::ofSpec($type),
        ));
    }

    /**
     * Object initializer: new Person { Name = "Ann" }
     *
     * @template T of object
     * @param T $object
     * @param array<string, mixed> $values
     * @return T
     */
    public static function init(object $object, array $values): object
    {
        foreach ($values as $name => $value) {
            $object->$name = $value;
        }

        return $object;
    }

    /**
     * Collection initializer: new List<int> { 1, 2 } (calls Add for each element).
     *
     * @template T of object
     * @param T $collection
     * @return T
     */
    public static function collect(object $collection, iterable $items): object
    {
        foreach ($items as $item) {
            $collection->Add($item);
        }

        return $collection;
    }

    /**
     * Collection initializer with pairs: new Dictionary<string, int> { { "a", 1 } }
     *
     * @template T of object
     * @param T $collection
     * @param list<list<mixed>> $pairs
     * @return T
     */
    public static function addPairs(object $collection, array $pairs): object
    {
        foreach ($pairs as $pair) {
            $collection->Add(...$pair);
        }

        return $collection;
    }

    /**
     * Index initializer: new Dictionary<string, int> { ["a"] = 1 }
     *
     * @template T of object
     * @param T $collection
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return T
     */
    public static function setIndexes(object $collection, array $pairs): object
    {
        foreach ($pairs as [$key, $value]) {
            $collection[$key] = $value;
        }

        return $collection;
    }
}
