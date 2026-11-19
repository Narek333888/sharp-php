<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\FormatException;
use SharpPhp\Runtime\System\OverflowException;

/**
 * int.Parse / int.TryParse for every integral type, given the type's range.
 *
 * @internal used by Int32, Int64, Int16, Byte, ...
 */
final class IntegerParser
{
    /**
     * @param string $typeName as .NET words it in messages ("an Int32", "an unsigned byte")
     * @throws FormatException when $s is not an integer
     * @throws OverflowException when it does not fit the range
     */
    public static function parse(string $s, int $min, int $max, string $typeName): int
    {
        $digits = self::digits($s);
        if ($digits === null) {
            throw new FormatException("The input string '$s' was not in a correct format.");
        }
        $value = filter_var($digits, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new OverflowException("Value was either too large or too small for $typeName.");
        }

        return $value;
    }

    /**
     * Like parse(), but reports failure by returning false (and setting $result to 0).
     *
     * @param-out int $result
     */
    public static function tryParse(?string $s, ?int &$result, int $min, int $max): bool
    {
        $digits = $s === null ? null : self::digits($s);
        $value = $digits === null ? false : filter_var($digits, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            $result = 0;

            return false;
        }
        $result = $value;

        return true;
    }

    /** "  +007 " => "+7"; null when $s is not an optionally signed run of digits. */
    private static function digits(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^([+-]?)0*(\d+)$/', $s, $m) !== 1) {
            return null;
        }

        return $m[1] . $m[2];
    }
}
