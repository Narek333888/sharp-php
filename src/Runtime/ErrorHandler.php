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

    /**
     * PHP's wording for using null as an object, array or collection: "Attempt to read property
     * "Name" on null", "foreach() argument must be of type array|object, null given", or null
     * passed as the receiver of a runtime helper (\mb_strlen($s) for s.Length, String_::ToUpper($s)).
     */
    public static function isNullAccess(string $message): bool
    {
        return preg_match('/ on null$|^Trying to access array offset on (value of type )?null$|^foreach\(\) argument must be of type array\|object, null given$/', $message) === 1
            || preg_match('/^(mb_\w+|count|SharpPhp\\\\Runtime\\\\[\w\\\\]+::\w+)\(\): Argument #1 \(\$\w+\) must be of type [^,]+, null given/', $message) === 1;
    }

    /**
     * A PHP engine error that means C#'s NullReferenceException, as one; any other throwable unchanged.
     * Generated code calls it where a catch clause needs the C# exception type.
     */
    public static function translate(\Throwable $e): \Throwable
    {
        if ($e instanceof \Error && self::isNullAccess($e->getMessage())) {
            return (new NullReferenceException())->withLocation($e->getFile(), $e->getLine());
        }

        return $e;
    }

    public static function restore(): void
    {
        restore_error_handler();
    }
}
