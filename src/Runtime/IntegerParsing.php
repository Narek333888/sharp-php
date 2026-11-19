<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\FormatException;
use SharpPhp\Runtime\System\OverflowException;

/**
 * Parse/TryParse shared by the integral types (int, long, short, byte, ...).
 * The using class declares MinValue and MaxValue.
 *
 * @internal
 */
trait IntegerParsing
{
    public static function Parse(string $s): int
    {
        $value = filter_var(trim($s), FILTER_VALIDATE_INT);
        if ($value === false) {
            if (preg_match('/^\s*[+-]?\d+\s*$/', $s) === 1) {
                throw new OverflowException(sprintf('Value was either too large or too small for %s.', self::NAME));
            }
            throw new FormatException("The input string '$s' was not in a correct format.");
        }
        if ($value < static::MinValue || $value > static::MaxValue) {
            throw new OverflowException(sprintf('Value was either too large or too small for %s.', self::NAME));
        }

        return $value;
    }

    public static function TryParse(?string $s, ?int &$result): bool
    {
        $value = $s === null ? false : filter_var(trim($s), FILTER_VALIDATE_INT);
        if ($value === false || $value < static::MinValue || $value > static::MaxValue) {
            $result = 0;

            return false;
        }
        $result = $value;

        return true;
    }
}
