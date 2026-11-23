<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\FormatException;

/**
 * .NET's numeric format strings (en-US), for ToString(format), string.Format and interpolation.
 *
 * - Standard formats: B, C, D, E, F, G, N, P, R, X with a precision (F2, N0, P1, X8, ...). A double is
 *   rounded from its exact binary value, half to even (2.5.ToString("F0") is "2"), as .NET Core does.
 * - Custom formats: 0 # . , % ‰ E0 E+0 e-0, 'literal', "literal", \x, and sections "pos;neg;zero".
 *   A double first takes 15 significant digits, which are rounded half away from zero (7.5 with "0"
 *   is "8").
 * - A decimal (a PHP float to the runtime, see the README) takes 15 significant digits and rounds half
 *   away from zero in every format (2.345m with "F2" is "2.35"); an integer is exact and rounds half
 *   away from zero too (12250 with "G3" is "1.23E+04").
 * - A negative double keeps its sign when it rounds to zero (-0.001 with "F2" is "-0.00"); a decimal
 *   or an integer does not, as in .NET.
 *
 * The digits of a number are kept as a string and the position of its decimal point, as .NET's
 * number buffer: 1234.5 is ("12345", 4), 0.00123 is ("123", -2), zero is ("", 0).
 *
 * @internal
 */
final class NumberFormat
{
    /** @var array<string, array{0: string, 1: ?int}|false> parsed standard formats, false for custom ones */
    private static array $standard = [];

    /** @var array<string, list<string>> custom formats split into their sections */
    private static array $sections = [];

    /**
     * @var array<string, array{tokens: list<array{0: string, 1: string}>, ints: int, minInts: int,
     *     fracs: int, minFracs: int, grouping: bool, scale: int, exponent: ?array{0: string, 1: string, 2: int},
     *     simple: bool}>
     */
    private static array $parsed = [];

    /** @param 'double'|'float'|'decimal' $type the C# type of a PHP float */
    public static function format(int|float $value, string $format, string $type = 'double'): string
    {
        if (is_float($value) && !is_finite($value)) {
            return is_nan($value) ? 'NaN' : ($value > 0 ? '∞' : '-∞');
        }
        if (count(self::$standard) > 500) {
            self::$standard = self::$sections = self::$parsed = [];
        }
        $standard = self::$standard[$format] ??= preg_match('/^([A-Za-z])(\d{0,9})$/', $format, $m) === 1
            ? [$m[1], $m[2] === '' ? null : (int) $m[2]]
            : ($format === '' ? ['G', null] : false);

        return $standard !== false
            ? self::standard($value, $standard[0], $standard[1], $type)
            : self::custom($value, $format, $type);
    }

    /** A decimal's ToString(): its digits without an exponent (0.0000000001, 100000000000000000000). */
    public static function decimalText(float $value): string
    {
        if (!is_finite($value)) {
            return self::format($value, 'G');
        }
        [$digits, $scale] = self::digits($value);

        return ($digits !== '' && $value < 0 ? '-' : '') . self::plain($digits, $scale);
    }

    // ---- standard formats --------------------------------------------------

    /** @param 'double'|'float'|'decimal' $type */
    private static function standard(int|float $value, string $letter, ?int $precision, string $type): string
    {
        $decimal = $type === 'decimal';
        $upper = strtoupper($letter);
        switch ($upper) {
            case 'F':
            case 'N':
            case 'C':
                if (!$decimal && ($precision ?? 2) <= 53) {
                    // sprintf's text is .NET's for an integer and for a double (rounded from its exact
                    // value, half to even, -0.001 is -0.00), except -0.0, whose sign PHP does not write.
                    $text = is_int($value) ? $value . (($precision ?? 2) > 0 ? '.' . str_repeat('0', $precision ?? 2) : '') : sprintf('%.' . ($precision ?? 2) . 'F', $value);
                    $negative = $text[0] === '-' || ($value === 0.0 && fdiv(1.0, $value) < 0);
                    if ($upper === 'F') {
                        return $negative && $text[0] !== '-' ? '-' . $text : $text;
                    }
                    $text = ltrim($text, '-');
                    $point = strpos($text, '.');
                    $text = $point === false ? self::group($text) : self::group(substr($text, 0, $point)) . substr($text, $point);

                    return ($negative ? '-' : '') . ($upper === 'C' ? '$' : '') . $text;
                }
                [$digits, $scale] = self::fixedDigits($value, $precision ?? 2, $decimal);
                [$int, $frac] = self::split($digits, $scale, $precision ?? 2);
                $int = $upper === 'F' ? $int : self::group($int);
                $text = $frac === '' ? $int : $int . '.' . $frac;

                return self::sign($value, $digits, $decimal) . ($upper === 'C' ? '$' . $text : $text);
            case 'P':
                // As .NET: the digits with two more decimals, the decimal point moved by two.
                [$digits, $scale] = self::fixedDigits($value, ($precision ?? 2) + 2, $decimal);
                [$int, $frac] = self::split($digits, $scale + 2, $precision ?? 2);

                return self::sign($value, $digits, $decimal) . self::group($int) . ($frac === '' ? '' : '.' . $frac) . '%';
            case 'E':
                return self::exponential($value, $precision ?? 6, $letter === 'e', $decimal);
            case 'G':
            case 'R':
                // A float writes its shortest single-precision digits (1f / 3 is 0.33333334).
                if ($type === 'float' && is_float($value) && ($upper === 'R' || in_array($precision, [null, 0], true))) {
                    return Op::singleText($value);
                }
                if ($upper === 'R') {
                    return is_int($value) ? (string) $value : ($decimal ? self::decimalText($value) : Op::shortest($value));
                }

                return self::general($value, $precision === 0 ? null : $precision, $letter === 'g', $decimal);
            case 'D':
                if (is_int($value)) {
                    return ($value < 0 ? '-' : '') . str_pad(ltrim((string) $value, '-'), $precision ?? 0, '0', STR_PAD_LEFT);
                }
                break;
            case 'X':
            case 'B':
                if (is_int($value)) {
                    // A negative int is two's complement of 32 bits when it fits an int (-1 is FFFFFFFF).
                    $bits = $value < 0 && $value >= -2147483648 ? $value & 0xFFFFFFFF : $value;
                    $text = $upper === 'B' ? decbin($bits) : ($letter === 'x' ? dechex($bits) : strtoupper(dechex($bits)));

                    return str_pad($text, $precision ?? 0, '0', STR_PAD_LEFT);
                }
                break;
        }

        throw new FormatException('Format specifier was invalid.');
    }

    /**
     * The digits of |value| rounded to $decimals decimals: a double from its exact value, half to even
     * (sprintf is correctly rounded); a decimal from 15 significant digits, half away from zero.
     *
     * @return array{0: string, 1: int}
     */
    private static function fixedDigits(int|float $value, int $decimals, bool $decimal): array
    {
        if (is_int($value)) {
            return self::digits($value);
        }
        if ($decimal) {
            [$digits, $scale] = self::digits($value);

            return self::round($digits, $scale, $scale + $decimals);
        }
        $text = sprintf('%.' . min($decimals, 53) . 'F', abs($value));
        $point = strpos($text, '.');
        $all = ltrim(str_replace('.', '', $text), '0');
        $scale = ($point === false ? strlen($text) : $point) - (strlen(str_replace('.', '', $text)) - strlen($all));

        return [rtrim($all, '0'), $all === '' ? 0 : $scale];
    }

    /** "-" for a negative number: a double keeps it at zero (-0.001 with F2 is -0.00), others do not. */
    private static function sign(int|float $value, string $digits, bool $decimal): string
    {
        if (is_float($value) && !$decimal) {
            return $value < 0 || ($value === 0.0 && fdiv(1.0, $value) < 0) ? '-' : '';
        }

        return $value < 0 && $digits !== '' ? '-' : '';
    }

    /** E: d.ddddddE+ddd (at least three exponent digits). */
    private static function exponential(int|float $value, int $precision, bool $lower, bool $decimal): string
    {
        if (is_float($value) && !$decimal) {
            [$mantissa, $exponent] = explode('e', sprintf('%.' . min($precision, 53) . 'e', abs($value)));
            $mantissa = str_pad($mantissa, $precision + ($precision > 0 ? 2 : 1), '0');
            $exponent = (int) $exponent;
        } else {
            [$digits, $scale] = self::digits($value);
            [$digits, $scale] = self::round($digits, $scale, $precision + 1);
            $digits = str_pad($digits, $precision + 1, '0');
            $mantissa = $digits[0] . ($precision > 0 ? '.' . substr($digits, 1) : '');
            $exponent = $digits === str_repeat('0', $precision + 1) ? 0 : $scale - 1;
            $value = $digits[0] === '0' ? 0 : $value; // zero has no sign
        }
        $sign = self::sign($value, $value == 0 ? '' : '1', $decimal);

        return $sign . $mantissa . ($lower ? 'e' : 'E') . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 3, '0', STR_PAD_LEFT);
    }

    /** G: the shortest of fixed and scientific notation with $precision significant digits. */
    private static function general(int|float $value, ?int $precision, bool $lower, bool $decimal): string
    {
        if ($precision === null) {
            return match (true) {
                is_int($value) => (string) $value,
                $decimal => self::decimalText($value),
                default => Op::shortest($value),
            };
        }
        if (is_float($value) && !$decimal) {
            // sprintf rounds the exact value correctly.
            [$mantissa, $exponent] = explode('e', sprintf('%.' . ($precision - 1) . 'e', abs($value)));
            $digits = rtrim(str_replace('.', '', $mantissa), '0');
            $scale = (int) $exponent + 1;
        } else {
            [$digits, $scale] = self::digits($value);
            [$digits, $scale] = self::round($digits, $scale, $precision);
        }
        $sign = self::sign($value, $digits, $decimal);
        if ($digits === '') {
            return $sign . '0';
        }
        // As .NET: fixed-point notation when -5 < exponent < precision.
        $exponent = $scale - 1;
        if ($exponent >= $precision || $exponent < -4) {
            $text = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '')
                . 'E' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        } else {
            $text = self::plain($digits, $scale);
        }

        return $sign . ($lower ? strtolower($text) : $text);
    }

    // ---- custom formats ----------------------------------------------------

    /** @param 'double'|'float'|'decimal' $type */
    private static function custom(int|float $value, string $format, string $type): string
    {
        $sections = self::$sections[$format] ??= self::sections($format);
        $floating = is_float($value) && $type !== 'decimal';
        // As .NET: a float's custom format starts from 7 significant digits, a double's from 15.
        [$digits, $scale] = self::digits($value, $type === 'float' ? 7 : 15);
        $negative = $floating ? $value < 0 || ($value === 0.0 && fdiv(1.0, $value) < 0) : $value < 0;
        // As .NET: zero takes the third section, a negative number the second; one that is missing or
        // empty is the first. A number that rounds to zero takes the zero section, if there is one.
        $section = self::section($sections, $digits === '' ? 2 : ($negative ? 1 : 0));
        $text = self::apply($digits, $scale, self::parse($sections[$section]));
        if ($text === null) {
            $zero = self::section($sections, 2);
            if ($zero !== $section) {
                $section = $zero;
                $text = self::apply('', 0, self::parse($sections[$section]));
            }
            $negative = $negative && $floating; // a decimal or an integer that rounds to zero has no sign
        }
        $text ??= self::apply('', 0, self::parse($sections[$section])) ?? '';

        return ($negative && $section === 0 && $text !== '' ? '-' : '') . $text;
    }

    /**
     * The index of the section that formats a value: the requested one, or the first when it is
     * missing or empty.
     *
     * @param list<string> $sections
     */
    private static function section(array $sections, int $index): int
    {
        return ($sections[$index] ?? '') === '' ? 0 : $index;
    }

    /**
     * The sections of a custom format: split at the ; that are not quoted or escaped.
     *
     * @return list<string>
     */
    private static function sections(string $format): array
    {
        $sections = [];
        $current = '';
        $quote = null;
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            $c = $format[$i];
            if ($quote !== null) {
                $quote = $c === $quote ? null : $quote;
            } elseif ($c === '\\' && $i + 1 < $length) {
                $current .= $c . $format[++$i];
                continue;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === ';') {
                $sections[] = $current;
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $sections[] = $current;

        return $sections;
    }

    /**
     * One section of a custom format, read once: its tokens ('lit' text, 'int' and 'frac' placeholders,
     * 'point', 'exp'), how many integer and fraction placeholders it has, how many of them must show a
     * digit (from the first 0 before the point, up to the last 0 after it), and its scaling
     * (% is +2, ‰ +3, each comma just before the point or at the end of the integer part -3).
     *
     * @return array{tokens: list<array{0: string, 1: string}>, ints: int, minInts: int, fracs: int,
     *     minFracs: int, grouping: bool, scale: int, exponent: ?array{0: string, 1: string, 2: int},
     *     simple: bool}
     */
    private static function parse(string $format): array
    {
        if (isset(self::$parsed[$format])) {
            return self::$parsed[$format];
        }
        $tokens = [];
        $chars = mb_str_split($format);
        $n = count($chars);
        $ints = $fracs = $minFracs = $scale = $commas = 0;
        $firstZero = null;
        $point = $grouping = false;
        $exponent = null;
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $tokens[] = ['lit', $chars[++$i]];
            } elseif ($c === "'" || $c === '"') {
                $text = '';
                for ($i++; $i < $n && $chars[$i] !== $c; $i++) {
                    $text .= $chars[$i];
                }
                $tokens[] = ['lit', $text];
            } elseif (($c === 'E' || $c === 'e') && $exponent === null && $i + 1 < $n
                && ($chars[$i + 1] === '0' || (($chars[$i + 1] === '+' || $chars[$i + 1] === '-') && ($chars[$i + 2] ?? '') === '0'))) {
                $sign = $chars[$i + 1] === '0' ? '' : $chars[++$i];
                $zeros = 0;
                while (($chars[$i + 1] ?? '') === '0') {
                    $zeros++;
                    $i++;
                }
                $exponent = [$c, $sign, $zeros];
                $tokens[] = ['exp', ''];
            } elseif ($c === '0' || $c === '#') {
                if ($point) {
                    $fracs++;
                    $minFracs = $c === '0' ? $fracs : $minFracs;
                    $tokens[] = ['frac', $c];
                } else {
                    // A comma between integer placeholders groups the digits by thousands.
                    $grouping = $grouping || $commas > 0;
                    $commas = 0;
                    $firstZero ??= $c === '0' ? $ints : null;
                    $ints++;
                    $tokens[] = ['int', $c];
                }
            } elseif ($c === ',') {
                $commas += !$point && $ints > 0 ? 1 : 0;
            } elseif ($c === '.') {
                if (!$point) {
                    $point = true;
                    $tokens[] = ['point', ''];
                }
            } else {
                $scale += $c === '%' ? 2 : ($c === '‰' ? 3 : 0);
                $tokens[] = ['lit', $c];
            }
            if ($point && $commas > 0) { // commas just before the point divide by 1000 each
                $scale -= 3 * $commas;
                $commas = 0;
            }
        }
        $scale -= 3 * $commas; // and so do commas at the end of the integer part

        $simple = $tokens !== []; // only placeholders and a point: the digits need no placing
        foreach ($tokens as [$kind]) {
            $simple = $simple && $kind !== 'lit' && $kind !== 'exp';
        }

        return self::$parsed[$format] = [
            'tokens' => $tokens, 'ints' => $ints, 'minInts' => $firstZero === null ? 0 : $ints - $firstZero,
            'fracs' => $fracs, 'minFracs' => $minFracs, 'grouping' => $grouping, 'scale' => $scale,
            'exponent' => $exponent, 'simple' => $simple,
        ];
    }

    /**
     * A number's digits written with a parsed section; null when a non-zero number rounds to zero
     * (the caller then picks the zero section).
     *
     * @param array{tokens: list<array{0: string, 1: string}>, ints: int, minInts: int, fracs: int,
     *     minFracs: int, grouping: bool, scale: int, exponent: ?array{0: string, 1: string, 2: int},
     *     simple: bool} $section
     */
    private static function apply(string $digits, int $scale, array $section): ?string
    {
        $exponent = 0;
        $wasZero = $digits === '';
        if (!$wasZero) {
            $scale += $section['scale'];
            if ($section['exponent'] !== null) {
                // As many integer digits as the section has integer placeholders; the rest is the exponent.
                [$digits, $scale] = self::round($digits, $scale, $section['ints'] + $section['fracs']);
                $exponent = $scale - $section['ints'];
                $scale = $section['ints'];
            } else {
                [$digits, $scale] = self::round($digits, $scale, $scale + $section['fracs']);
            }
            if ($digits === '') {
                return null;
            }
        }
        [$int, $frac] = self::split($digits, $scale, $section['fracs']);
        $int = ltrim($int, '0');
        if (strlen($int) < $section['minInts']) {
            $int = str_pad($int, $section['minInts'], '0', STR_PAD_LEFT);
        }
        $printFracs = max(strlen(rtrim($frac, '0')), $section['minFracs']);
        if ($section['simple']) {
            $int = $section['grouping'] ? self::group($int) : $int;

            return $printFracs > 0 ? $int . '.' . substr($frac, 0, $printFracs) : $int;
        }
        $length = strlen($int);
        $ints = $section['ints'];
        $grouping = $section['grouping'];
        $out = '';
        $next = 0; // the next integer digit to write
        $intIndex = $fracIndex = 0;
        foreach ($section['tokens'] as [$kind, $text]) {
            switch ($kind) {
                case 'lit':
                    $out .= $text;
                    break;
                case 'int':
                    // Digits fill the placeholders from the right; the first also takes those without one.
                    $until = $length - $ints + ++$intIndex;
                    while ($next < $until) {
                        $out .= $int[$next++];
                        if ($grouping && $next < $length && ($length - $next) % 3 === 0) {
                            $out .= ',';
                        }
                    }
                    break;
                case 'point':
                    if ($ints === 0) {
                        $out .= $int; // no integer placeholders: the integer digits go before the point
                    }
                    $out .= $printFracs > 0 ? '.' : '';
                    break;
                case 'frac':
                    $out .= $fracIndex < $printFracs ? ($frac[$fracIndex] ?? '0') : '';
                    $fracIndex++;
                    break;
                default: // 'exp'
                    [$letter, $sign, $zeros] = $section['exponent'] ?? ['E', '', 1];
                    $out .= $letter . ($exponent < 0 ? '-' : ($sign === '+' ? '+' : ''))
                        . str_pad((string) abs($exponent), $zeros, '0', STR_PAD_LEFT);
            }
        }

        return $out;
    }

    // ---- digits ------------------------------------------------------------

    /**
     * The significant digits of |value| and the position of its decimal point: an integer's exact
     * digits, a float's first $significant digits (correctly rounded by sprintf).
     *
     * @return array{0: string, 1: int}
     */
    private static function digits(int|float $value, int $significant = 15): array
    {
        if (is_int($value)) {
            $text = ltrim((string) $value, '-');

            return $value === 0 ? ['', 0] : [rtrim($text, '0'), strlen($text)];
        }
        if ($value == 0) {
            return ['', 0];
        }
        [$mantissa, $exponent] = explode('e', sprintf('%.' . ($significant - 1) . 'e', abs($value)));

        return [rtrim($mantissa[0] . substr($mantissa, 2), '0'), (int) $exponent + 1];
    }

    /**
     * Digits rounded half away from zero to the first $keep of them (as .NET rounds its digit buffer).
     *
     * @return array{0: string, 1: int}
     */
    private static function round(string $digits, int $scale, int $keep): array
    {
        if ($keep >= strlen($digits)) {
            return [$digits, $scale];
        }
        if ($keep < 0 || ($keep === 0 && $digits[0] < '5')) {
            return ['', 0];
        }
        if ($digits[$keep] < '5') {
            $digits = rtrim(substr($digits, 0, $keep), '0');

            return [$digits, $digits === '' ? 0 : $scale];
        }
        // Round up: drop the 9s that carry, then add one to the digit before them.
        $kept = rtrim(substr($digits, 0, $keep), '9');
        if ($kept === '') {
            return ['1', $scale + 1];
        }

        return [substr($kept, 0, -1) . chr(ord($kept[-1]) + 1), $scale];
    }

    /**
     * The integer and fraction digits of a number, the fraction with $decimals digits.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $digits, int $scale, int $decimals): array
    {
        if ($digits === '') {
            return ['0', str_repeat('0', $decimals)];
        }
        if ($scale <= 0) {
            $frac = str_repeat('0', min(-$scale, $decimals)) . $digits;

            return ['0', str_pad(substr($frac, 0, $decimals), $decimals, '0')];
        }
        $int = strlen($digits) >= $scale ? substr($digits, 0, $scale) : str_pad($digits, $scale, '0');

        return [$int, str_pad(substr($digits, $scale, $decimals), $decimals, '0')];
    }

    /** Digits written out in full: 0.00123, 1200, 12.5. */
    private static function plain(string $digits, int $scale): string
    {
        if ($digits === '') {
            return '0';
        }
        if ($scale <= 0) {
            return '0.' . str_repeat('0', -$scale) . $digits;
        }
        if ($scale >= strlen($digits)) {
            return str_pad($digits, $scale, '0');
        }

        return substr($digits, 0, $scale) . '.' . substr($digits, $scale);
    }

    private static function group(string $int): string
    {
        $length = strlen($int);
        if ($length <= 3) {
            return $int;
        }
        $first = $length % 3 ?: 3;
        $out = substr($int, 0, $first);
        for ($i = $first; $i < $length; $i += 3) {
            $out .= ',' . substr($int, $i, 3);
        }

        return $out;
    }
}
