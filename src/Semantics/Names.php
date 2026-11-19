<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * Mapping between C#-style dotted names and PHP names.
 *
 *  - The "System" namespace maps to the bundled runtime: System.Console => SharpPhp\Runtime\System\Console
 *  - Class names that are reserved in PHP get a trailing underscore: List => List_, String => String_
 */
final class Names
{
    public const RUNTIME_NAMESPACE = 'SharpPhp\\Runtime';

    /** Words PHP does not accept as class names (lowercase). */
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include',
        'include_once', 'instanceof', 'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match',
        'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected',
        'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch', 'throw',
        'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /** @var array<string, true>|null */
    private static ?array $reservedSet = null;

    public static function isReserved(string $name): bool
    {
        self::$reservedSet ??= array_fill_keys(self::RESERVED, true);

        return isset(self::$reservedSet[strtolower($name)]);
    }

    public static function mangle(string $shortName): string
    {
        return self::isReserved($shortName) ? $shortName . '_' : $shortName;
    }

    /** C# namespace (dotted) to PHP namespace. */
    public static function phpNamespace(string $dotted): string
    {
        if ($dotted === '') {
            return '';
        }
        if ($dotted === 'System' || str_starts_with($dotted, 'System.')) {
            $dotted = 'SharpPhp.Runtime.' . $dotted;
        }

        return str_replace('.', '\\', $dotted);
    }

    /** C# full type name (dotted) to PHP FQN (no leading backslash). */
    public static function phpClass(string $dotted): string
    {
        $pos = strrpos($dotted, '.');
        if ($pos === false) {
            return self::mangle($dotted);
        }

        return self::phpNamespace(substr($dotted, 0, $pos)) . '\\' . self::mangle(substr($dotted, $pos + 1));
    }

    /** PHP FQN back to the C#-style name, for messages. */
    public static function csharpName(string $phpFqn): string
    {
        $fqn = ltrim($phpFqn, '\\');
        $prefix = self::RUNTIME_NAMESPACE . '\\';
        if (str_starts_with($fqn, $prefix)) {
            $fqn = substr($fqn, strlen($prefix));
        }
        $parts = explode('\\', $fqn);
        $last = array_pop($parts);
        if (str_ends_with($last, '_') && self::isReserved(substr($last, 0, -1))) {
            $last = substr($last, 0, -1);
        }
        $parts[] = $last;

        return implode('.', $parts);
    }

    public static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    public static function namespaceOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? '' : substr($fqn, 0, $pos);
    }
}
