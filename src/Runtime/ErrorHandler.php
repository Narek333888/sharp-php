<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\IndexOutOfRangeException;
use SharpPhp\Runtime\System\NullReferenceException;

/**
 * Turns PHP warnings and notices into exceptions, as C# has no "warnings" at runtime.
 * Reading a missing array element becomes IndexOutOfRangeException, reading a member of null
 * becomes NullReferenceException.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity) || $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                return false;
            }
            if (str_starts_with($message, 'Undefined array key') || str_starts_with($message, 'Uninitialized string offset')) {
                throw (new IndexOutOfRangeException())->withLocation($file, $line);
            }
            if (self::isNullAccess($message)) {
                throw (new NullReferenceException())->withLocation($file, $line);
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /** PHP's wording for using null as an object or array ("Attempt to read property "Name" on null"). */
    public static function isNullAccess(string $message): bool
    {
        return preg_match('/ on null$|^Trying to access array offset on (value of type )?null$/', $message) === 1;
    }

    public static function restore(): void
    {
        restore_error_handler();
    }
}
