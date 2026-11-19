<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Char. Characters are one-character strings in SharpPHP.
 */
final class Char
{
    public static function IsDigit(string $c): bool
    {
        return preg_match('/^\p{Nd}$/u', $c) === 1;
    }

    public static function IsLetter(string $c): bool
    {
        return preg_match('/^\p{L}$/u', $c) === 1;
    }

    public static function IsLetterOrDigit(string $c): bool
    {
        return preg_match('/^[\p{L}\p{Nd}]$/u', $c) === 1;
    }

    public static function IsWhiteSpace(string $c): bool
    {
        return preg_match('/^\s$/u', $c) === 1;
    }

    public static function IsUpper(string $c): bool
    {
        return preg_match('/^\p{Lu}$/u', $c) === 1;
    }

    public static function IsLower(string $c): bool
    {
        return preg_match('/^\p{Ll}$/u', $c) === 1;
    }

    /** @sharp-return char */
    public static function ToUpper(string $c): string
    {
        return mb_strtoupper($c);
    }

    /** @sharp-return char */
    public static function ToLower(string $c): string
    {
        return mb_strtolower($c);
    }
}
