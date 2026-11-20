<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.String. Static members (string.Join) and, taking the string as the first
 * argument, instance members (text.ToUpper() compiles to String_::ToUpper($text)).
 */
final class String_
{
    public const string Empty = '';

    // ---- static members ----------------------------------------------

    public static function Join(string $separator, mixed ...$values): string
    {
        if (count($values) === 1 && is_iterable($values[0])) {
            $values = $values[0];
        }
        $parts = [];
        foreach ($values as $value) {
            $parts[] = Op::str($value);
        }

        return implode($separator, $parts);
    }

    public static function Concat(mixed ...$values): string
    {
        return implode('', array_map(Op::str(...), $values));
    }

    public static function IsNullOrEmpty(?string $value): bool
    {
        return $value === null || $value === '';
    }

    public static function IsNullOrWhiteSpace(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /** Composite formatting: string.Format("{0} is {1:F2}", name, value) */
    public static function Format(string $format, mixed ...$args): string
    {
        $result = preg_replace_callback('/\{\{|\}\}|\{(\d+)(?:,(-?\d+))?(?::([^}]*))?\}/', static function (array $m) use ($args): string {
            if ($m[0] === '{{') {
                return '{';
            }
            if ($m[0] === '}}') {
                return '}';
            }
            $index = (int) ($m[1] ?? 0);
            if (!array_key_exists($index, $args)) {
                throw new FormatException('Index (zero based) must be greater than or equal to zero and less than the size of the argument list.');
            }
            $text = isset($m[3]) && $m[3] !== '' ? Op::format($args[$index], $m[3]) : Op::str($args[$index]);
            if (isset($m[2]) && $m[2] !== '') {
                $width = (int) $m[2];
                $text = $width < 0 ? str_pad($text, -$width) : str_pad($text, $width, ' ', STR_PAD_LEFT);
            }

            return $text;
        }, $format);

        return $result ?? $format;
    }

    /**
     * string.Compare(a, b), string.Compare(a, b, ignoreCase) and string.Compare(a, b, StringComparison):
     * negative, zero or positive; null comes first.
     */
    public static function Compare(?string $a, ?string $b, bool|StringComparison $ignoreCase = false): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) - ($b === null ? 0 : 1);
        }

        return match (true) {
            $ignoreCase === StringComparison::Ordinal => strcmp($a, $b) <=> 0,
            // As .NET: OrdinalIgnoreCase compares the upper-case forms.
            $ignoreCase === StringComparison::OrdinalIgnoreCase => strcmp(mb_strtoupper($a), mb_strtoupper($b)) <=> 0,
            $ignoreCase === true || ($ignoreCase instanceof StringComparison && $ignoreCase->ignoresCase()) => strcmp(mb_strtolower($a), mb_strtolower($b)) <=> 0,
            default => Op::compareStrings($a, $b),
        };
    }

    /** string.Equals(a, b) and a.Equals(b), optionally with a StringComparison. */
    public static function Equals(?string $a, ?string $b, ?StringComparison $comparisonType = null): bool
    {
        if ($a === null || $b === null || $comparisonType?->ignoresCase() !== true) {
            return $a === $b;
        }

        return mb_strtolower($a) === mb_strtolower($b);
    }

    // ---- instance members (receiver first) ---------------------------

    public static function ToString(string $s): string
    {
        return $s;
    }

    public static function ToUpper(string $s): string
    {
        return mb_strtoupper($s);
    }

    public static function ToLower(string $s): string
    {
        return mb_strtolower($s);
    }

    public static function ToUpperInvariant(string $s): string
    {
        return mb_strtoupper($s);
    }

    public static function ToLowerInvariant(string $s): string
    {
        return mb_strtolower($s);
    }

    public static function Trim(string $s, ?string $chars = null): string
    {
        return $chars === null ? trim($s) : trim($s, $chars);
    }

    public static function TrimStart(string $s, ?string $chars = null): string
    {
        return $chars === null ? ltrim($s) : ltrim($s, $chars);
    }

    public static function TrimEnd(string $s, ?string $chars = null): string
    {
        return $chars === null ? rtrim($s) : rtrim($s, $chars);
    }

    public static function Contains(string $s, string $value, ?StringComparison $comparisonType = null): bool
    {
        return $comparisonType?->ignoresCase() === true ? mb_stripos($s, $value) !== false : str_contains($s, $value);
    }

    public static function StartsWith(string $s, string $value, ?StringComparison $comparisonType = null): bool
    {
        if ($comparisonType?->ignoresCase() === true) {
            return mb_strtolower(mb_substr($s, 0, mb_strlen($value))) === mb_strtolower($value);
        }

        return str_starts_with($s, $value);
    }

    public static function EndsWith(string $s, string $value, ?StringComparison $comparisonType = null): bool
    {
        if ($comparisonType?->ignoresCase() === true) {
            $length = mb_strlen($value);

            return $length === 0 || mb_strtolower(mb_substr($s, -$length)) === mb_strtolower($value);
        }

        return str_ends_with($s, $value);
    }

    /**
     * text.IndexOf(value), (value, startIndex), (value, StringComparison) and
     * (value, startIndex, StringComparison): the position of the first match, or -1.
     */
    public static function IndexOf(string $s, string $value, int|StringComparison $startIndex = 0, ?StringComparison $comparisonType = null): int
    {
        if ($startIndex instanceof StringComparison) {
            [$comparisonType, $startIndex] = [$startIndex, 0];
        }
        $pos = $comparisonType?->ignoresCase() === true ? mb_stripos($s, $value, $startIndex) : mb_strpos($s, $value, $startIndex);

        return $pos === false ? -1 : $pos;
    }

    public static function LastIndexOf(string $s, string $value, ?StringComparison $comparisonType = null): int
    {
        $pos = $comparisonType?->ignoresCase() === true ? mb_strripos($s, $value) : mb_strrpos($s, $value);

        return $pos === false ? -1 : $pos;
    }

    public static function Substring(string $s, int $startIndex, ?int $length = null): string
    {
        $total = mb_strlen($s);
        if ($startIndex < 0 || $startIndex > $total) {
            throw new ArgumentOutOfRangeException('startIndex', 'startIndex cannot be larger than length of string.');
        }
        if ($length !== null && ($length < 0 || $startIndex + $length > $total)) {
            throw new ArgumentOutOfRangeException('length', 'Index and length must refer to a location within the string.');
        }

        return mb_substr($s, $startIndex, $length);
    }

    public static function Replace(string $s, string $oldValue, ?string $newValue, ?StringComparison $comparisonType = null): string
    {
        if ($oldValue === '') {
            throw new ArgumentException('String cannot be of zero length.', 'oldValue');
        }
        if ($comparisonType?->ignoresCase() === true) {
            // The replacement is literal text: "$1" and "\" in it mean themselves.
            return (string) preg_replace('/' . preg_quote($oldValue, '/') . '/iu', addcslashes($newValue ?? '', '\\$'), $s);
        }

        return str_replace($oldValue, $newValue ?? '', $s);
    }

    /**
     * text.Split(',') / text.Split(", ") / text.Split(new[] { ',', ';' })
     *
     * @param string|list<string>|null $separator
     * @return string[]
     */
    public static function Split(string $s, string|array|null $separator = null, int $options = 0): array
    {
        $separators = $separator === null ? [' '] : (array) $separator;
        $pattern = '/' . implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $separators)) . '/u';
        $parts = preg_split($pattern, $s) ?: [$s];
        if ($options & 1) { // StringSplitOptions.RemoveEmptyEntries
            $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        }

        return $parts;
    }

    public static function PadLeft(string $s, int $totalWidth, string $paddingChar = ' '): string
    {
        $missing = $totalWidth - mb_strlen($s);

        return $missing > 0 ? str_repeat($paddingChar, $missing) . $s : $s;
    }

    public static function PadRight(string $s, int $totalWidth, string $paddingChar = ' '): string
    {
        $missing = $totalWidth - mb_strlen($s);

        return $missing > 0 ? $s . str_repeat($paddingChar, $missing) : $s;
    }

    public static function Insert(string $s, int $startIndex, string $value): string
    {
        return mb_substr($s, 0, $startIndex) . $value . mb_substr($s, $startIndex);
    }

    public static function Remove(string $s, int $startIndex, ?int $count = null): string
    {
        return mb_substr($s, 0, $startIndex) . ($count === null ? '' : mb_substr($s, $startIndex + $count));
    }

    /**
     * @return list<string> one-character strings
     * @sharp-return char[]
     */
    public static function ToCharArray(string $s): array
    {
        return mb_str_split($s);
    }

    public static function CompareTo(string $s, ?string $other): int
    {
        return self::Compare($s, $other);
    }

    public static function GetHashCode(string $s): int
    {
        return crc32($s);
    }
}
