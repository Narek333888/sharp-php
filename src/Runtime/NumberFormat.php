<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\FormatException;

/**
 * .NET's numeric format strings (en-US), for ToString(format), string.Format and interpolation:
 *
 * - standard: C, D, E, F, G, N, P, R, X with a precision (F2, N0, P1, X8, ...). A double is rounded
 *   from its exact value, half to even (2.5.ToString("F0") is "2"), as .NET Core does;
 * - custom: 0 # . , % ‰ E0 E+0 e-0, 'literal', "literal", \x, and sections "pos;neg;zero". A double
 *   is first taken to 15 significant digits, then rounded half away from zero (7.5 with "0" is "8");
 * - a decimal (a PHP float, see the README) is taken to 15 significant digits and rounded half away
 *   from zero in every format (2.345m.ToString("F2") is "2.35"), as .NET rounds decimals.
 *
 * @internal
 */
final class NumberFormat
{
    public static function format(int|float $value, string $format, bool $decimal = false): string
    {
        if (preg_match('/^([A-Za-z])(\d{0,2})$/', $format, $m) === 1) {
            return self::standard($value, $m[1], $m[2] === '' ? null : (int) $m[2], $decimal);
        }

        return self::custom($value, $format, $decimal);
    }

    // ---- standard formats ------------------------------------------------

    private static function standard(int|float $value, string $letter, ?int $precision, bool $decimal): string
    {
        $kind = strtoupper($letter);
        if (is_float($value) && (is_nan($value) || is_infinite($value)) && !in_array($kind, ['D', 'X'], true)) {
            return Op::shortest($value);
        }

        return match ($kind) {
            'F' => self::signed($value, self::fixed($value, $precision ?? 2, $decimal, false), standard: true),
            'N' => self::signed($value, self::fixed($value, $precision ?? 2, $decimal, true), standard: true),
            'P' => self::signed($value, self::fixed(is_int($value) ? $value * 100 : $value * 100.0, $precision ?? 2, $decimal, true), standard: true) . '%',
            'C' => (self::isNegative($value, true) ? '-' : '') . '$' . self::fixed($value, $precision ?? 2, $decimal, true),
            'E' => self::exponential($value, $precision ?? 6, $letter === 'e', $decimal),
            'D' => is_int($value)
                ? ($value < 0 ? '-' : '') . str_pad(ltrim((string) $value, '-'), $precision ?? 0, '0', STR_PAD_LEFT)
                : throw new FormatException('Format specifier was invalid.'),
            'X' => is_int($value)
                ? str_pad(self::hex($value, $letter === 'x'), $precision ?? 0, '0', STR_PAD_LEFT)
                : throw new FormatException('Format specifier was invalid.'),
            'G' => self::general($value, $precision, $letter === 'g', $decimal),
            'R' => is_float($value) ? Op::shortest($value) : (string) $value,
            default => throw new FormatException('Format specifier was invalid.'),
        };
    }

    /** "-" before a negative number (also one that rounds to zero: -0.001 with F2 is "-0.00"). */
    private static function signed(int|float $value, string $digits, bool $standard): string
    {
        return (self::isNegative($value, $standard) ? '-' : '') . $digits;
    }

    /** Negative; for the standard formats -0.0 too, as .NET Core writes it. */
    private static function isNegative(int|float $value, bool $standard): bool
    {
        return $value < 0 || ($standard && is_float($value) && $value === 0.0 && fdiv(1.0, $value) < 0);
    }

    /** The absolute value with $decimals decimals, grouped by thousands when $group. */
    private static function fixed(int|float $value, int $decimals, bool $decimal, bool $group): string
    {
        [$int, $frac] = self::digits($value, $decimals, $decimal ? 'decimal' : 'exact');
        $int = $group ? self::group($int) : $int;

        return $frac === '' ? $int : $int . '.' . $frac;
    }

    /**
     * The absolute value's integer and fraction digits, rounded to $decimals:
     * 'exact' rounds the double's exact value half to even (sprintf is correctly rounded),
     * 'significant' and 'decimal' take 15 significant digits first and round half away from zero.
     *
     * @param 'exact'|'significant'|'decimal' $mode
     * @return array{0: string, 1: string}
     */
    private static function digits(int|float $value, int $decimals, string $mode): array
    {
        if (is_int($value)) {
            return [ltrim((string) $value, '-'), str_repeat('0', $decimals)];
        }
        $abs = abs($value);
        if ($mode === 'exact') {
            $text = sprintf('%.' . min($decimals, 53) . 'F', $abs);
            [$int, $frac] = array_pad(explode('.', $text, 2), 2, '');

            return [$int, str_pad($frac, $decimals, '0')];
        }

        return self::roundHalfUp(self::significant($abs), $decimals);
    }

    /** The plain decimal text of 15 significant digits of $abs ("1.45", "0.000123"). */
    private static function significant(float $abs): string
    {
        if ($abs === 0.0) {
            return '0';
        }
        $e = sprintf('%.14e', $abs); // 1.45000000000000e+0
        [$mantissa, $exponent] = explode('e', $e);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $exp = (int) $exponent;
        if ($digits === '') {
            return '0';
        }
        if ($exp >= 0) {
            $intLen = $exp + 1;
            $padded = str_pad($digits, $intLen, '0');

            return substr($padded, 0, $intLen) . (strlen($padded) > $intLen ? '.' . substr($padded, $intLen) : '');
        }

        return '0.' . str_repeat('0', -$exp - 1) . $digits;
    }

    /**
     * A decimal text rounded to $decimals, half away from zero.
     *
     * @return array{0: string, 1: string}
     */
    private static function roundHalfUp(string $text, int $decimals): array
    {
        [$int, $frac] = array_pad(explode('.', $text, 2), 2, '');
        if (strlen($frac) <= $decimals) {
            return [$int, str_pad($frac, $decimals, '0')];
        }
        $keep = $int . substr($frac, 0, $decimals);
        if ((int) $frac[$decimals] >= 5) {
            $keep = self::increment($keep);
        }
        $intLen = strlen($keep) - $decimals;

        return [ltrim(substr($keep, 0, $intLen), '0') ?: '0', (string) substr($keep, $intLen)];
    }

    /** "1299" + 1 = "1300", "99" + 1 = "100" (a string of digits). */
    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                return substr($digits, 0, $i) . (string) ((int) $digits[$i] + 1) . str_repeat('0', strlen($digits) - $i - 1);
            }
        }

        return '1' . str_repeat('0', strlen($digits));
    }

    private static function group(string $int): string
    {
        return strrev(implode(',', str_split(strrev($int), 3)));
    }

    /** E: d.ddddddE+ddd (at least 3 exponent digits). */
    private static function exponential(int|float $value, int $precision, bool $lower, bool $decimal): string
    {
        $abs = abs((float) $value);
        if ($abs === 0.0) {
            [$mantissa, $exp] = ['0' . ($precision > 0 ? '.' . str_repeat('0', $precision) : ''), 0];
        } elseif ($decimal) {
            $exp = (int) floor(log10($abs));
            [$int, $frac] = self::roundHalfUp(self::significant($abs / 10 ** $exp), $precision);
            if (strlen($int) > 1) { // 9.99 rounded up to 10.0
                $exp++;
                [$int, $frac] = self::roundHalfUp(self::significant($abs / 10 ** $exp), $precision);
            }
            $mantissa = $int . ($precision > 0 ? '.' . $frac : '');
        } else {
            [$mantissa, $e] = explode('e', sprintf('%.' . min($precision, 53) . 'e', $abs));
            $exp = (int) $e;
        }
        $sign = self::isNegative($value, true) ? '-' : '';

        return $sign . $mantissa . ($lower ? 'e' : 'E') . ($exp < 0 ? '-' : '+') . str_pad((string) abs($exp), 3, '0', STR_PAD_LEFT);
    }

    private static function general(int|float $value, ?int $precision, bool $lower, bool $decimal): string
    {
        if (is_int($value) && ($precision === null || $precision >= strlen(ltrim((string) $value, '-')))) {
            return (string) $value;
        }
        $text = $precision === null && !$decimal ? Op::shortest((float) $value) : self::generalDigits((float) $value, $precision ?? 15);

        return $lower ? strtolower($text) : $text;
    }

    private static function generalDigits(float $value, int $precision): string
    {
        return Op::formatGeneral($value, max($precision, 1));
    }

    /** Negative ints as two's complement: of 32 bits when the value fits an int (-1 is FFFFFFFF). */
    private static function hex(int $value, bool $lower): string
    {
        if ($value < 0 && $value >= -2147483648) {
            $value &= 0xFFFFFFFF;
        }
        $hex = dechex($value);

        return $lower ? $hex : strtoupper($hex);
    }

    /**
     * The integer and fraction digits of $abs * 10^$power, rounded to $decimals half away from zero
     * (from 15 significant digits for a double).
     *
     * @return array{0: string, 1: string}
     */
    private static function scaledDigits(int|float $abs, int $power, int $decimals): array
    {
        if (is_int($abs) && $power >= 0 && $power < 10) {
            return [(string) ($abs * 10 ** $power), str_repeat('0', $decimals)];
        }
        if (is_int($abs) && $power < 0 && $abs < 1e15) {
            // Integer arithmetic on the digits: exact (1234567890 with "#,##0,," is 1,235).
            $digits = str_pad((string) $abs, -$power + 1, '0', STR_PAD_LEFT);
            $text = substr($digits, 0, strlen($digits) + $power) . '.' . substr($digits, $power);

            return self::roundHalfUp($text, $decimals);
        }

        return self::roundHalfUp(self::significant((float) $abs * 10.0 ** $power), $decimals);
    }

    // ---- custom formats ----------------------------------------------------

    private static function custom(int|float $value, string $format, bool $decimal): string
    {
        $sections = self::sections($format);
        $negative = $value < 0;
        $section = $sections[0];
        $explicitNegative = false;
        if ($negative && isset($sections[1]) && $sections[1] !== '') {
            $section = $sections[1];
            $explicitNegative = true;
        }
        $result = self::section($value, $section, $decimal);
        // A value that rounds to zero takes the zero section.
        if (isset($sections[2]) && $result['zero']) {
            return self::section(0, $sections[2], $decimal)['text'];
        }
        if ($explicitNegative) {
            return $result['text'];
        }

        // A negative value keeps its sign when it rounds to zero (-0.004 with 0.00 is -0.00), as in .NET.
        return ($negative && $result['text'] !== '' ? '-' : '') . $result['text'];
    }

    /**
     * The sections of a custom format (split at ; outside quotes and escapes).
     *
     * @return list<string>
     */
    private static function sections(string $format): array
    {
        $sections = [''];
        $quote = null;
        $chars = mb_str_split($format);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($quote !== null) {
                $quote = $c === $quote ? null : $quote;
            } elseif ($c === '\\' && $i + 1 < $n) {
                $sections[count($sections) - 1] .= $c . $chars[++$i];
                continue;
            } elseif ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === ';') {
                $sections[] = '';
                continue;
            }
            $sections[count($sections) - 1] .= $c;
        }

        return $sections;
    }

    /**
     * One section of a custom format applied to the absolute value.
     *
     * @return array{text: string, zero: bool}
     */
    private static function section(int|float $value, string $format, bool $decimal): array
    {
        // Pass 1: the placeholders.
        $tokens = [];
        $chars = mb_str_split($format);
        $n = count($chars);
        $intPlaceholders = 0;
        $firstZero = null;
        $fracZeros = 0;
        $fracPlaceholders = 0;
        $seenPoint = false;
        $grouping = false;
        $scale = 0;
        $percent = 0;
        $exponent = null;
        $pendingCommas = 0;
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\' && $i + 1 < $n) {
                $tokens[] = ['lit', $chars[++$i]];
                continue;
            }
            if ($c === "'" || $c === '"') {
                $text = '';
                for ($i++; $i < $n && $chars[$i] !== $c; $i++) {
                    $text .= $chars[$i];
                }
                $tokens[] = ['lit', $text];
                continue;
            }
            if (($c === 'E' || $c === 'e') && $exponent === null && $i + 1 < $n
                && ($chars[$i + 1] === '0' || (in_array($chars[$i + 1], ['+', '-'], true) && $i + 2 < $n && $chars[$i + 2] === '0'))) {
                $sign = in_array($chars[$i + 1], ['+', '-'], true) ? $chars[++$i] : '';
                $digits = 0;
                while ($i + 1 < $n && $chars[$i + 1] === '0') {
                    $digits++;
                    $i++;
                }
                $exponent = ['letter' => $c, 'sign' => $sign, 'digits' => $digits];
                $tokens[] = ['exp', null];
                continue;
            }
            if ($c === '0' || $c === '#') {
                if ($pendingCommas > 0) {
                    $grouping = $grouping || !$seenPoint; // a comma between digits groups
                    $pendingCommas = 0;
                }
                if ($seenPoint) {
                    $fracPlaceholders++;
                    if ($c === '0') {
                        $fracZeros = $fracPlaceholders;
                    }
                    $tokens[] = ['frac', $c];
                } else {
                    if ($c === '0' && $firstZero === null) {
                        $firstZero = $intPlaceholders;
                    }
                    $intPlaceholders++;
                    $tokens[] = ['int', $c];
                }
                continue;
            }
            if ($c === ',') {
                if (!$seenPoint && $intPlaceholders > 0) {
                    $pendingCommas++;
                }
                continue;
            }
            if ($c === '.') {
                if (!$seenPoint) {
                    $scale += $pendingCommas; // commas just before the point divide by 1000
                    $pendingCommas = 0;
                    $seenPoint = true;
                    $tokens[] = ['point', null];
                }
                continue;
            }
            if ($c === '%') {
                $percent += 2;
            } elseif ($c === '‰') {
                $percent += 3;
            }
            $tokens[] = ['lit', $c];
        }
        $scale += $pendingCommas; // commas at the end of the integer part divide by 1000

        // The number: scaled (%, ‰, commas before the point, the exponent) and rounded.
        $abs = abs($value);
        $exp = 0;
        if ($exponent !== null && $abs != 0) {
            // The mantissa has as many integer digits as the format has placeholders before the point.
            $exp = (int) floor(log10((float) $abs)) - (max($intPlaceholders, 1) - 1);
        }
        [$int, $frac] = self::scaledDigits($abs, $percent - 3 * $scale - $exp, $fracPlaceholders);
        if ($exponent !== null && strlen(ltrim($int, '0')) > max($intPlaceholders, 1)) {
            $exp++; // 9.96 rounded to 10.0: one more for the exponent
            [$int, $frac] = self::scaledDigits($abs, $percent - 3 * $scale - $exp, $fracPlaceholders);
        }
        $zero = trim($int, '0') === '' && trim($frac, '0') === '';
        $int = ltrim($int, '0');

        // Pass 2: the text. Integer digits fill the placeholders from the right; the first placeholder
        // also takes the digits that have none.
        $minInt = $firstZero === null ? 0 : $intPlaceholders - $firstZero;
        $int = str_pad($int, $minInt, '0', STR_PAD_LEFT);
        $length = strlen($int);
        $intIndex = 0;
        $fracIndex = 0;
        $printFrac = max(strlen(rtrim($frac, '0')), $fracZeros);
        $out = '';
        $written = 0;
        $emit = static function (string $digits) use (&$out, &$written, $length, $grouping): void {
            foreach ($digits === '' ? [] : str_split($digits) as $digit) {
                $out .= $digit;
                $written++;
                if ($grouping && $length - $written > 0 && ($length - $written) % 3 === 0) {
                    $out .= ',';
                }
            }
        };
        if ($intPlaceholders === 0 && $int !== '') {
            $emit($int); // no integer placeholders: the integer digits go first
        }
        foreach ($tokens as [$kind, $c]) {
            switch ($kind) {
                case 'lit':
                    $out .= $c;
                    break;
                case 'int':
                    $index = $length - $intPlaceholders + $intIndex; // the digit of this placeholder
                    if ($intIndex === 0) {
                        $emit($index >= 0 ? substr($int, 0, $index + 1) : '');
                    } elseif ($index >= 0) {
                        $emit($int[$index]);
                    }
                    $intIndex++;
                    break;
                case 'point':
                    if ($printFrac > 0) {
                        $out .= '.';
                    }
                    break;
                case 'frac':
                    if ($fracIndex < $printFrac) {
                        $out .= $frac[$fracIndex] ?? '0';
                    }
                    $fracIndex++;
                    break;
                case 'exp':
                    if ($exponent !== null) {
                        $sign = $exp < 0 ? '-' : ($exponent['sign'] === '+' ? '+' : '');
                        $out .= $exponent['letter'] . $sign . str_pad((string) abs($exp), $exponent['digits'], '0', STR_PAD_LEFT);
                    }
                    break;
            }
        }

        return ['text' => $out, 'zero' => $zero];
    }
}
