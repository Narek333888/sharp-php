<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\Op;

/**
 * System.Console
 */
final class Console
{
    /** @var resource|null */
    private static $input = null;

    /**
     * Console.WriteLine(value) or Console.WriteLine("{0} + {1}", a, b)
     */
    public static function WriteLine(mixed $value = '', mixed ...$args): void
    {
        echo self::text($value, $args), PHP_EOL;
    }

    public static function Write(mixed $value, mixed ...$args): void
    {
        echo self::text($value, $args);
    }

    public static function ReadLine(): ?string
    {
        self::$input ??= defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        $line = fgets(self::$input);

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /** @param list<mixed> $args */
    private static function text(mixed $value, array $args): string
    {
        return $args !== [] && is_string($value) ? String_::Format($value, ...$args) : Op::str($value);
    }
}
