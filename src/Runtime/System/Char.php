<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Char. Characters are one-character strings in SharpPHP.
 */
final class Char
{
    /** char.IsDigit(c): a Unicode decimal digit (category Nd), so also '٣' (Arabic-Indic three), as in .NET. */
    public static function IsDigit(string $c): bool
    {
        return preg_match('/^\p{Nd}$/u', $c) === 1;
    }

    /** char.IsLetter(c): a Unicode letter of any kind (category L: Lu, Ll, Lt, Lm or Lo), as in .NET. */
    public static function IsLetter(string $c): bool
    {
        return preg_match('/^\p{L}$/u', $c) === 1;
    }

    /** char.IsLetterOrDigit(c): char.IsLetter(c) or char.IsDigit(c) (Unicode categories L and Nd), as in .NET. */
    public static function IsLetterOrDigit(string $c): bool
    {
        return preg_match('/^[\p{L}\p{Nd}]$/u', $c) === 1;
    }

    /**
     * char.IsWhiteSpace(c): \t to \r, space, U+0085, U+00A0 and the other Unicode space, line and paragraph separators,
     * as in .NET; PCRE also counts U+180E (Mongolian vowel separator), which .NET does not.
     */
    public static function IsWhiteSpace(string $c): bool
    {
        return preg_match('/^\s$/u', $c) === 1;
    }

    /** char.IsUpper(c): an upper-case letter (Unicode category Lu), as in .NET; title-case 'ǅ' is not one. */
    public static function IsUpper(string $c): bool
    {
        return preg_match('/^\p{Lu}$/u', $c) === 1;
    }

    /** char.IsLower(c): a lower-case letter (Unicode category Ll), as in .NET; title-case 'ǅ' is not one. */
    public static function IsLower(string $c): bool
    {
        return preg_match('/^\p{Ll}$/u', $c) === 1;
    }

    /** @sharp-return char */
    public static function ToUpper(string $c): string
    {
        return String_::upper($c);
    }

    /** @sharp-return char */
    public static function ToLower(string $c): string
    {
        return String_::lower($c);
    }
}
