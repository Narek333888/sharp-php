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

    /** @sharp-typed-numbers $values elements */
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

    /**
     * new string('-', 5) and new string(chars[, start, length]): C#'s string constructors.
     *
     * @internal the compiler calls it for "new string(...)"
     * @param string|list<mixed> $value a char, or an array of chars
     */
    public static function __new(string|array $value, int $countOrStart = 0, ?int $length = null): string
    {
        if (is_string($value)) {
            if ($countOrStart < 0) {
                throw new ArgumentOutOfRangeException('count', 'Non-negative number required.');
            }

            return str_repeat($value, $countOrStart);
        }
        $chars = $value;
        if ($length === null) {
            return implode('', array_map(Op::str(...), $chars));
        }
        if ($countOrStart < 0 || $length < 0 || $countOrStart + $length > count($chars)) {
            throw new ArgumentOutOfRangeException('startIndex', 'Index and length must refer to a location within the string.');
        }

        return implode('', array_map(Op::str(...), array_slice($chars, $countOrStart, $length)));
    }

    /**
     * string.Concat(a, b, ...), and string.Concat(sequence) of a List, an array or a LINQ result, whose
     * elements are concatenated (C#'s Concat(IEnumerable<string>) and Concat<T>(IEnumerable<T>)).
     *
     * @sharp-typed-numbers $values elements
     */
    public static function Concat(mixed ...$values): string
    {
        if (count($values) === 1 && is_iterable($values[0])) {
            $values = is_array($values[0]) ? $values[0] : iterator_to_array($values[0], false);
        }

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

    /**
     * Composite formatting: string.Format("{0} is {1:F2}", name, value)
     *
     * @sharp-typed-numbers $args
     */
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
                $text = Op::align($text, (int) $m[2]); // counts characters, not bytes
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
            $ignoreCase === StringComparison::Ordinal => self::compareOrdinal($a, $b, firstCharFirst: true),
            // As .NET: OrdinalIgnoreCase compares the upper-case forms.
            $ignoreCase === StringComparison::OrdinalIgnoreCase => self::compareOrdinal(self::upper($a), self::upper($b), firstCharFirst: false),
            $ignoreCase === true || ($ignoreCase instanceof StringComparison && $ignoreCase->ignoresCase()) => strcmp(self::lower($a), self::lower($b)) <=> 0,
            default => Op::compareStrings($a, $b),
        };
    }

    /**
     * .NET's ordinal comparison: the difference of the first chars when they differ (an empty string's
     * is 0: "" against "a" is -97), else of the first chars that differ, else of the lengths.
     */
    private static function compareOrdinal(string $a, string $b, bool $firstCharFirst): int
    {
        if ($a === $b) {
            return 0;
        }
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        if ($firstCharFirst && ($x[0] ?? '') !== ($y[0] ?? '')) {
            return (isset($x[0]) ? mb_ord($x[0]) : 0) - (isset($y[0]) ? mb_ord($y[0]) : 0);
        }
        for ($i = 0, $n = min(count($x), count($y)); $i < $n; $i++) {
            if ($x[$i] !== $y[$i]) {
                return mb_ord($x[$i]) - mb_ord($y[$i]);
            }
        }

        return count($x) - count($y);
    }

    /**
     * Upper case as .NET's ToUpper: one char for one char ("ß" stays "ß", "ǆ" is "Ǆ"), never PHP's
     * full mapping ("ß" to "SS", "ﬁ" to "FI").
     *
     * @internal
     */
    public static function upper(string $s): string
    {
        return mb_convert_case($s, MB_CASE_UPPER_SIMPLE, 'UTF-8');
    }

    /** Lower case as .NET's ToLower: one char for one char, and no final sigma ("ΣΑΣ" is "σασ"). @internal */
    public static function lower(string $s): string
    {
        return mb_convert_case($s, MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /** string.Equals(a, b) and a.Equals(b), optionally with a StringComparison. */
    public static function Equals(?string $a, ?string $b, ?StringComparison $comparisonType = null): bool
    {
        if ($a === null || $b === null || $comparisonType?->ignoresCase() !== true) {
            return $a === $b;
        }

        return self::upper($a) === self::upper($b);
    }

    // ---- instance members (receiver first) ---------------------------

    public static function ToString(string $s): string
    {
        return $s;
    }

    public static function ToUpper(string $s): string
    {
        return self::upper($s);
    }

    public static function ToLower(string $s): string
    {
        return self::lower($s);
    }

    public static function ToUpperInvariant(string $s): string
    {
        return self::upper($s);
    }

    public static function ToLowerInvariant(string $s): string
    {
        return self::lower($s);
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
            return self::upper(mb_substr($s, 0, mb_strlen($value))) === self::upper($value);
        }

        return str_starts_with($s, $value);
    }

    public static function EndsWith(string $s, string $value, ?StringComparison $comparisonType = null): bool
    {
        if ($comparisonType?->ignoresCase() === true) {
            $length = mb_strlen($value);

            return $length === 0 || self::upper(mb_substr($s, -$length)) === self::upper($value);
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
     * text.Split(',') / text.Split(", ") / text.Split(new[] { ',', ';' }) / text.Split() (white space),
     * each with a count (the most parts: the last one is the rest of the string) and options.
     *
     * @param string|list<string>|null $separator
     * @return string[]
     */
    public static function Split(string $s, string|array|null $separator = null, StringSplitOptions|int $countOrOptions = PHP_INT_MAX, ?StringSplitOptions $options = null): array
    {
        [$count, $options] = $countOrOptions instanceof StringSplitOptions ? [PHP_INT_MAX, $countOrOptions] : [$countOrOptions, $options ?? StringSplitOptions::None];
        if ($count < 0) {
            throw new ArgumentOutOfRangeException('count', 'Count cannot be less than zero.');
        }
        $trim = ($options->value & StringSplitOptions::TrimEntries->value) !== 0;
        $removeEmpty = ($options->value & StringSplitOptions::RemoveEmptyEntries->value) !== 0;
        $separators = array_values(array_filter((array) ($separator ?? []), static fn (string $p): bool => $p !== ''));
        if ($count === 0 || ($s === '' && $removeEmpty)) {
            return [];
        }
        $pattern = $separator === null || ((array) $separator === [] )
            ? '/[\s\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u' // char.IsWhiteSpace
            : ($separators === [] ? null : '/' . implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $separators)) . '/u');
        $finish = static fn (string $p): string => $trim ? self::Trim($p) : $p;
        if ($pattern === null || $count === 1) {
            $only = $finish($s);

            return $removeEmpty && $only === '' ? [] : [$only];
        }
        // The parts, each with the offset where the rest of the string after it starts.
        $pieces = preg_split($pattern, $s, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [[$s, 0]];
        $parts = [];
        foreach ($pieces as $i => [$piece, $offset]) {
            $last = $i === count($pieces) - 1;
            if (count($parts) === $count - 1 && !$last) {
                if ($removeEmpty && $finish($piece) === '') {
                    continue; // empty parts before the rest are skipped
                }
                // The rest of the string, from this part on.
                $rest = $finish(substr($s, $offset));
                if (!($removeEmpty && $rest === '')) {
                    $parts[] = $rest;
                }

                return $parts;
            }
            $piece = $finish($piece);
            if (!($removeEmpty && $piece === '')) {
                $parts[] = $piece;
            }
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

    /**
     * The chars of a string used as an IEnumerable<char> ("text".Reverse()); null stays null for ?.
     *
     * @internal
     * @return list<string>|null
     */
    public static function chars(?string $s): ?array
    {
        return $s === null ? null : mb_str_split($s);
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
