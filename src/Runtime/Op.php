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

        return self::number($a, '+', $b) + self::number($b, '+', $a);
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
        // IEEE division: 1 / -0.0 is -Infinity, 0.0 / 0.0 is NaN.
        return fdiv((float) self::number($a, '/', $b), (float) self::number($b, '/', $a));
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

        return fmod((float) self::number($a, '%', $b), (float) self::number($b, '%', $a));
    }

    /** An operand of an arithmetic operator on dynamic values: C# only does arithmetic on numbers. */
    private static function number(mixed $value, string $operator, mixed $other): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        throw new System\InvalidOperationException(sprintf(
            "Operator '%s' cannot be applied to operands of type '%s' and '%s'",
            $operator,
            TypeName::of($value),
            TypeName::of($other),
        ));
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
            $value instanceof FlagsEnum && $value instanceof \UnitEnum => str_replace('_', ', ', $value->name),
            $value instanceof \UnitEnum => $value->name,
            is_object($value) && method_exists($value, 'ToString') => self::str($value->ToString()),
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
        return self::general($value, 15);
    }

    /**
     * The "G" format of a double: ToString() is G15, up to 15 significant digits, as .NET Framework
     * printed doubles. It also suits decimal, which is a PHP float here too: 0.07m * 100 prints 7,
     * not 7.000000000000001. "R" (null $precision) gives the shortest digits that read back as the
     * same double, as .NET Core's ToString() does (0.1 + 0.2 is 0.30000000000000004).
     * Scientific notation when the exponent reaches the precision (17 for the shortest digits, as .NET:
     * 1E+16 is 10000000000000000, 1E+17 is 1E+17) or is below -4.
     */
    private static function general(float $value, ?int $precision, int $shortestLimit = 17): string
    {
        // var_export() writes the shortest round-trip digits (serialize_precision -1).
        $repr = $precision === null ? var_export($value, true) : sprintf('%.' . (max($precision, 1) - 1) . 'E', $value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:E([+-]\d+))?$/', $repr, $m) !== 1) {
            return $repr;
        }
        [$sign, $int, $frac, $exp] = [$m[1], $m[2], $m[3] ?? '', (int) ($m[4] ?? 0)];
        $all = $int . $frac;
        $significant = ltrim($all, '0');
        $digits = rtrim($significant, '0');
        if ($digits === '') {
            return $sign . '0';
        }
        // Exponent of the first significant digit: 1234.5 has 3, 0.05 has -2.
        $exponent = strlen($int) + $exp - (strlen($all) - strlen($significant)) - 1;
        if ($exponent >= ($precision ?? $shortestLimit) || $exponent < -4) {
            $mantissa = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');

            return $sign . $mantissa . 'E' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        }
        if ($exponent < 0) {
            return $sign . '0.' . str_repeat('0', -$exponent - 1) . $digits;
        }
        $padded = str_pad($digits, $exponent + 1, '0');
        $fraction = substr($padded, $exponent + 1);

        return $sign . substr($padded, 0, $exponent + 1) . ($fraction !== '' ? '.' . $fraction : '');
    }

    /**
     * default(T) of a struct: an instance whose fields have their defaults (0, false, null), made without
     * running a constructor, as C# zeroes a struct.
     *
     * @internal
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function defaultStruct(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * After a call with an out argument of a value type: the variable's default (0, false, ...) when the
     * runtime method left it null, as C#'s out parameters are always assigned. Returns the call's result.
     *
     * @internal
     */
    public static function outDefault(mixed $result, mixed &$variable, mixed $default): mixed
    {
        $variable ??= $default;

        return $result;
    }

    /**
     * A double converted to an integral type, as .NET 9 and later do: NaN is 0, values out of range
     * saturate (int.MaxValue for (int)3e10). Narrower types go through int and are cut by the caller.
     *
     * @internal
     * @param 'int'|'long'|'uint'|'ulong' $type
     */
    public static function truncate(float $value, string $type): int
    {
        if (is_nan($value)) {
            return 0;
        }
        [$min, $max] = match ($type) {
            'long' => [-9.2233720368547758E18, 9.2233720368547758E18],
            'ulong' => [0.0, 9.2233720368547758E18],
            'uint' => [0.0, 4294967295.0],
            default => [-2147483648.0, 2147483647.0],
        };
        if ($value >= $max) {
            return $max >= 9.2233720368547758E18 ? PHP_INT_MAX : (int) $max;
        }
        if ($value <= $min) {
            return $min <= -9.2233720368547758E18 ? PHP_INT_MIN : (int) $min;
        }

        return (int) $value;
    }

    /**
     * An array as text: its type's name ("System.Int32[]"), which the compiler knows; null is "".
     *
     * @internal
     * @param array<mixed>|null $array
     */
    public static function arrayName(?array $array, string $name): string
    {
        return $array === null ? '' : $name;
    }

    /**
     * A float (System.Single) value: the double rounded to single precision, as float arithmetic gives it.
     *
     * @internal
     */
    public static function single(int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $rounded = unpack('g', pack('g', $value));

        return is_array($rounded) && is_float($rounded[1]) ? $rounded[1] : (float) $value;
    }

    /**
     * A float (System.Single) as .NET's ToString() writes it: the shortest digits that read back as the
     * same float (0.33333334, 3.4028235E+38), in scientific notation from an exponent of 9.
     *
     * @internal
     */
    public static function singleText(?float $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_nan($value) || is_infinite($value)) {
            return self::shortest($value);
        }
        for ($digits = 1; $digits <= 9; $digits++) {
            $candidate = (float) sprintf('%.' . ($digits - 1) . 'e', $value);
            if (self::single($candidate) === self::single($value)) {
                return self::general($candidate, null, 9);
            }
        }

        return self::general($value, null, 9);
    }

    /**
     * A double as .NET's ToString() writes it, for a value of static type double (null is "").
     *
     * @internal
     */
    public static function doubleText(?float $value): string
    {
        return $value === null ? '' : self::shortest($value);
    }

    /**
     * A double as .NET's ToString() writes it: the shortest digits that read back as the same double
     * (0.30000000000000004, 1E+21, 5E-324).
     *
     * @internal
     */
    public static function shortest(float $value): string
    {
        return is_nan($value) ? 'NaN' : (is_infinite($value) ? ($value > 0 ? '∞' : '-∞') : self::general($value, null));
    }

    /**
     * The alignment of an interpolation or composite format: {x,8} pads on the left to 8 characters,
     * {x,-8} on the right; longer text is kept whole.
     */
    public static function align(mixed $text, int $width): string
    {
        $text = self::str($text);

        return mb_str_pad($text, abs($width), ' ', $width < 0 ? STR_PAD_RIGHT : STR_PAD_LEFT);
    }

    /** Standard and custom C# format strings: F2, N0, D5, X, P1, E3, 0.00, yyyy-MM-dd ... */
    public static function format(mixed $value, string $format): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::dateFormat($format));
        }
        // $"{date:yyyy-MM-dd}", {0:c}: a value whose ToString takes a format (DateTime, TimeSpan) gets it.
        if (is_object($value) && method_exists($value, 'ToString')) {
            $first = (new \ReflectionMethod($value, 'ToString'))->getParameters()[0] ?? null;
            $type = $first?->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                return self::str($value->ToString($format));
            }
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
                'G' => is_float($value) && $digits !== null && $digits > 0 ? self::general($value, $digits) : self::str($value),
                'R' => is_float($value) ? self::general($value, null) : self::str($value),
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
        // .NET rounds a half away from zero (1234.5 with E3 is 1.235E+003); sprintf() rounds it to even.
        if ($value != 0 && is_finite($value)) {
            $exponent = (int) floor(log10(abs($value)));
            $mantissa = round($value / 10 ** $exponent, $digits, PHP_ROUND_HALF_UP);
            if (abs($mantissa) >= 10) {
                [$mantissa, $exponent] = [$mantissa / 10, $exponent + 1];
            }
            $text = number_format($mantissa, $digits, '.', '') . 'E' . ($exponent < 0 ? '-' : '+') . abs($exponent);
        } else {
            $text = sprintf('%.' . $digits . 'E', $value);
        }
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
     * The key HashSet, Dictionary, Distinct and GroupBy file a value under, agreeing with equals(): 1 and
     * 1.0 share one, 0.1 + 0.2 and 0.3 do not. Null for an object with its own GetHashCode() (a record,
     * a struct, DateTime, a class overriding it): such values are equal by Equals(), which the
     * collection asks among the values of the same hash code (see EqualityKeys).
     *
     * @internal
     */
    public static function key(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => 'i:' . $value,
            is_string($value) => 's:' . $value,
            $value === null => 'n',
            is_bool($value) => $value ? 'b:1' : 'b:0',
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 9.2e18
                ? 'i:' . (int) $value
                : 'f:' . (is_nan($value) ? 'NaN' : sprintf('%.17g', $value)),
            $value instanceof \UnitEnum => 'e:' . $value::class . '::' . $value->name,
            is_object($value) => !$value instanceof \Closure && method_exists($value, 'GetHashCode') ? null : 'o:' . spl_object_id($value),
            is_array($value) => 'a:' . self::arrayKey($value),
            default => 'x:' . get_debug_type($value),
        };
    }

    /**
     * "x is IList<T>" (ICollection, IReadOnlyList, IReadOnlyCollection): what the value's .NET type
     * implements; an array implements all of them.
     *
     * @internal
     */
    public static function isCollection(mixed $value, string $interface): bool
    {
        if (is_array($value)) {
            return true;
        }
        $implements = match (true) {
            $value instanceof \SharpPhp\Runtime\System\Collections\Generic\List_ => ['IList', 'ICollection', 'IReadOnlyList', 'IReadOnlyCollection'],
            $value instanceof \SharpPhp\Runtime\System\Collections\Generic\HashSet, $value instanceof \SharpPhp\Runtime\System\Collections\Generic\Dictionary => ['ICollection', 'IReadOnlyCollection'],
            $value instanceof \SharpPhp\Runtime\System\Collections\Generic\Queue, $value instanceof \SharpPhp\Runtime\System\Collections\Generic\Stack => ['IReadOnlyCollection'],
            is_object($value) && str_starts_with($value::class, 'SharpPhp\\Runtime\\') => [],
            $value instanceof \Traversable && $value instanceof \Countable => $value instanceof \ArrayAccess ? ['IList', 'ICollection', 'IReadOnlyList', 'IReadOnlyCollection'] : ['ICollection', 'IReadOnlyCollection'],
            default => [],
        };

        return in_array($interface, $implements, true);
    }

    /**
     * C#'s GetHashCode() of any value: an int is itself, equal values (by equals()) have equal codes, an
     * object without its own GetHashCode() has one of its identity.
     *
     * @internal
     */
    public static function hashCode(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value >= -2147483648 && $value <= 2147483647 ? $value : self::int32($value ^ ($value >> 32)),
            $value === null => 0,
            is_bool($value) => $value ? 1 : 0,
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 9.2e18
                ? self::hashCode((int) $value)
                : self::int32(crc32(is_nan($value) ? 'NaN' : pack('e', $value))),
            is_string($value) => self::int32(crc32($value)),
            $value instanceof \BackedEnum => self::hashCode($value->value),
            $value instanceof \UnitEnum => self::int32(crc32($value::class . '::' . $value->name)),
            is_object($value) => !$value instanceof \Closure && method_exists($value, 'GetHashCode') ? self::int32(self::intOf($value->GetHashCode())) : spl_object_id($value),
            is_array($value) => self::combine($value),
            default => 0,
        };
    }

    /**
     * The Equals() of a record and a struct: every field equal by equals() (C# compares each field with
     * its type's Equals: strings exactly, nested records by value, classes by reference), private ones
     * and those of base records included.
     *
     * @internal
     */
    public static function fieldsEqual(object $a, object $b): bool
    {
        $left = (array) $a;
        $right = (array) $b;
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $name => $value) {
            if (!array_key_exists($name, $right) || !self::equals($value, $right[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The GetHashCode() of a record and a struct: combined from every field's, so that equal values
     * (fieldsEqual) have equal codes.
     *
     * @internal
     */
    public static function fieldsHash(object $value): int
    {
        return self::combine((array) $value);
    }

    /** @param array<mixed> $values */
    private static function combine(array $values): int
    {
        $hash = 17;
        foreach ($values as $value) {
            $hash = ($hash * 31 + self::hashCode($value)) & 0xFFFFFFFF;
        }

        return self::int32($hash);
    }

    private static function intOf(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private static function int32(int $value): int
    {
        $value &= 0xFFFFFFFF;

        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    /** @param array<mixed> $value */
    private static function arrayKey(array $value): string
    {
        $parts = [];
        foreach ($value as $k => $item) {
            $parts[] = (is_int($k) ? $k : 's' . $k) . '=' . (self::key($item) ?? 'h' . self::hashCode($item));
        }

        return '[' . implode(',', $parts) . ']';
    }

    /**
     * C#'s lifted operator for a + b, a - b and -a on nullable values (DateTime? + TimeSpan): null when
     * an operand is null, else what the operator's method gives.
     */
    public static function lifted(\Closure $operator, mixed ...$operands): mixed
    {
        return in_array(null, $operands, true) ? null : $operator(...$operands);
    }

    /**
     * C#'s lifted comparison of nullable values (DateTime? < DateTime?): false when either side is
     * null, else the sign of $compare($a, $b) checked with the operator.
     *
     * @param \Closure(mixed, mixed): int $compare
     * @param '<'|'>'|'<='|'>=' $op
     */
    public static function liftedCompare(mixed $a, mixed $b, \Closure $compare, string $op): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        $sign = $compare($a, $b);

        return match ($op) {
            '<' => $sign < 0,
            '>' => $sign > 0,
            '<=' => $sign <= 0,
            '>=' => $sign >= 0,
        };
    }

    /**
     * Default ordering (Comparer<T>.Default): null first, enums by value, IComparable objects by
     * CompareTo, strings like .NET's culture-aware comparer (letters case-insensitively, then
     * lowercase first; digits as text: "10" < "9"), numbers numerically. Returns -1, 0 or 1.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        return match (true) {
            $a === $b => 0,
            $a === null => -1,
            $b === null => 1,
            $a instanceof \BackedEnum && $b instanceof \BackedEnum => $a->value <=> $b->value,
            is_object($a) && method_exists($a, 'CompareTo') => $a->CompareTo($b) <=> 0,
            is_object($b) && method_exists($b, 'CompareTo') => -($b->CompareTo($a) <=> 0),
            is_string($a) && is_string($b) => self::compareStrings($a, $b),
            default => $a <=> $b,
        };
    }

    /** string.Compare(a, b): case-insensitive order first; on a tie, lowercase sorts before uppercase. */
    public static function compareStrings(string $a, string $b): int
    {
        return (strcmp(mb_strtolower($a), mb_strtolower($b)) <=> 0) ?: (strcmp($b, $a) <=> 0);
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
            $stored = [];
            $accessors = []; // properties with accessor bodies: set through their init/set accessor
            foreach ($current->getProperties() as $property) {
                $name = $property->getName();
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }
                if ($property->isVirtual()) {
                    if (array_key_exists($name, $changes)) {
                        $accessors[$name] = $changes[$name];
                    }
                } elseif (array_key_exists($name, $changes)) {
                    $stored[$name] = $changes[$name];
                } elseif ($property->isInitialized($source)) {
                    $stored[$name] = $property->getValue($source);
                }
            }
            (\Closure::bind(static function () use ($copy, $stored, $accessors): void {
                foreach ([...$stored, ...$accessors] as $name => $value) {
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
     * Object initializer: new Person { Name = "Ann" }. A nested initializer (Items = { "a" },
     * Address = { City = "X" }) is applied to the member's current value, which must not be null.
     *
     * @template T of object
     * @param T $object
     * @param array<string, mixed> $values
     * @param array<string, \Closure(object): mixed> $nested
     * @return T
     */
    public static function init(object $object, array $values, array $nested = []): object
    {
        foreach ($values as $name => $value) {
            // The compiler checked that the setter is accessible where the initializer is written;
            // assign from the declaring class's scope so private(set)/protected(set) work too.
            $declaring = property_exists($object, $name) ? (new \ReflectionProperty($object, $name))->getDeclaringClass()->getName() : $object::class;
            (\Closure::bind(static function () use ($object, $name, $value): void {
                $object->$name = $value;
            }, null, $declaring))();
        }
        foreach ($nested as $name => $apply) {
            $current = $object->$name;
            if (!is_object($current)) {
                throw new System\NullReferenceException();
            }
            $apply($current);
        }

        return $object;
    }

    /**
     * d[key] += value / d[key]++ on a collection. PHP cannot change an ArrayAccess element in place
     * ("Indirect modification of overloaded element"), so the element is read, changed and written back.
     *
     * @param \ArrayAccess<mixed, mixed> $target
     * @param \Closure(mixed): mixed $change computes the new value from the old one
     * @param bool $returnOld true for postfix d[key]++ used as a value
     */
    public static function update(\ArrayAccess $target, mixed $key, \Closure $change, bool $returnOld = false): mixed
    {
        $old = $target[$key];
        $new = $change($old);
        $target[$key] = $new;

        return $returnOld ? $old : $new;
    }

    /**
     * The index of an array element that is written, checked: arr[i] = x compiles to
     * $arr[Op::index($arr, $i)] = $x. A C# array has a fixed length, so an index outside it throws
     * IndexOutOfRangeException, while PHP would add the element and make the array longer.
     *
     * The index is returned unchanged, so that the check sits inside the write and also covers
     * arr[i] += x, arr[i]++, arr[i] ??= x and out arr[i]. An element counts as present exactly when a
     * read finds it (a read of a missing one throws through ErrorHandler). The array is passed by
     * value, which PHP does without copying it.
     *
     * @param array<mixed> $array
     */
    public static function index(array $array, int|string $index): int|string
    {
        if (!\array_key_exists($index, $array)) {
            throw new System\IndexOutOfRangeException();
        }

        return $index;
    }

    /**
     * lock (gate): one thread runs SharpPHP code, so a lock is always free; the gate must be an object.
     *
     * @internal the compiler emits it for lock statements
     */
    public static function lockTarget(mixed $gate): void
    {
        if ($gate === null) {
            throw new System\ArgumentNullException('obj');
        }
    }

    /**
     * A number cut to a smaller integral type, as C# does without overflow checks: (byte)300 is 44,
     * (sbyte)200 is -56; also what byte b += 100 stores.
     *
     * @internal the compiler emits it for casts and for compound assignments to byte, short, ...
     */
    public static function narrow(int $value, string $type): int
    {
        return match ($type) {
            'byte' => $value & 0xFF,
            'sbyte' => (($value + 0x80) & 0xFF) - 0x80,
            'short' => (($value + 0x8000) & 0xFFFF) - 0x8000,
            'ushort' => $value & 0xFFFF,
            'int' => (($value + 0x80000000) & 0xFFFFFFFF) - 0x80000000,
            'uint' => $value & 0xFFFFFFFF,
            default => $value,
        };
    }

    /** The size of new T[n]: .NET throws OverflowException for a negative one. */
    public static function arraySize(int $size): int
    {
        if ($size < 0) {
            throw new System\OverflowException('Arithmetic operation resulted in an overflow.');
        }

        return $size;
    }

    /**
     * Collection initializer: new List<int> { 1, 2 } (calls Add for each element).
     *
     * @template T of object
     * @param T $collection
     * @param iterable<mixed> $items
     * @return T
     */
    public static function collect(object $collection, iterable $items): object
    {
        if (!method_exists($collection, 'Add')) {
            throw new System\InvalidOperationException('A collection initializer needs an Add method on ' . TypeName::of($collection));
        }
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
        if (!method_exists($collection, 'Add')) {
            throw new System\InvalidOperationException('A collection initializer needs an Add method on ' . TypeName::of($collection));
        }
        foreach ($pairs as $pair) {
            $collection->Add(...$pair);
        }

        return $collection;
    }

    /**
     * Index initializer: new Dictionary<string, int> { ["a"] = 1 }
     *
     * @template T of \ArrayAccess<mixed, mixed>
     * @param T $collection
     * @param list<array{0: mixed, 1: mixed}> $pairs
     * @return T
     */
    public static function setIndexes(\ArrayAccess $collection, array $pairs): \ArrayAccess
    {
        foreach ($pairs as [$key, $value]) {
            $collection[$key] = $value;
        }

        return $collection;
    }
}
