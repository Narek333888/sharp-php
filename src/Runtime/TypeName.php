<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * C#-style names of runtime values and PHP classes, for messages and ToString().
 */
final class TypeName
{
    private const NATIVE = [
        'stdClass' => 'System.Object',
        'DivisionByZeroError' => 'System.DivideByZeroException',
        'ArithmeticError' => 'System.ArithmeticException',
        'UnhandledMatchError' => 'System.InvalidOperationException',
        'ValueError' => 'System.ArgumentException',
        'TypeError' => 'System.InvalidCastException',
        'ArgumentCountError' => 'System.ArgumentException',
        'ErrorException' => 'System.Exception',
        'Exception' => 'System.Exception',
        'Error' => 'System.Exception',
    ];

    private const RESERVED = ['list', 'string', 'object', 'array', 'match', 'int', 'float', 'bool', 'mixed', 'iterable', 'callable', 'void', 'null', 'never'];

    /**
     * The .NET name of a PHP class: PHP's own exceptions as their .NET counterparts (TypeError is
     * System.InvalidCastException), runtime classes without the SharpPhp\Runtime prefix and the "_" of names PHP
     * reserves (String_ is System.String), namespaces joined by dots, and the generic arity
     * (System.Collections.Generic.List`1).
     */
    public static function ofClass(string $class): string
    {
        $class = ltrim($class, '\\');
        $original = $class;
        if (isset(self::NATIVE[$class])) {
            return self::NATIVE[$class];
        }
        $prefix = 'SharpPhp\\Runtime\\';
        if (str_starts_with($class, $prefix)) {
            $class = substr($class, strlen($prefix));
        }
        $parts = explode('\\', $class);
        $last = array_pop($parts);
        if (str_ends_with($last, '_') && in_array(strtolower(substr($last, 0, -1)), self::RESERVED, true)) {
            $last = substr($last, 0, -1);
        }
        $parts[] = $last . self::arity($original);

        return implode('.', $parts);
    }

    /** @var array<string, string> */
    private static array $arities = [];

    /** "`1" for a generic class (List<T>), as .NET names them: List`1; "" otherwise. */
    private static function arity(string $class): string
    {
        if (!isset(self::$arities[$class])) {
            $count = class_exists($class) || interface_exists($class)
                ? preg_match_all('/@template\s+\w+/', (string) (new \ReflectionClass($class))->getDocComment())
                : 0;
            self::$arities[$class] = $count > 0 ? '`' . $count : '';
        }

        return self::$arities[$class];
    }

    public static function of(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_int($value) => 'System.Int32',
            is_float($value) => 'System.Double',
            is_string($value) => 'System.String',
            is_bool($value) => 'System.Boolean',
            is_array($value) => 'System.Object[]',
            $value instanceof \Closure => 'System.Delegate',
            $value instanceof \Error && ErrorHandler::isNullAccess($value->getMessage()) => 'System.NullReferenceException',
            is_object($value) => self::ofClass($value::class),
            default => get_debug_type($value),
        };
    }

    /** Type spec ("int", "?App\Foo") as a C# name. */
    public static function ofSpec(string $spec): string
    {
        $nullable = str_starts_with($spec, '?');
        $base = ltrim($spec, '?');
        $name = match ($base) {
            'int' => 'System.Int32',
            'float' => 'System.Double',
            'string' => 'System.String',
            'char' => 'System.Char',
            'bool' => 'System.Boolean',
            'array' => 'System.Array',
            'mixed' => 'System.Object',
            'Closure' => 'System.Delegate',
            default => self::ofClass($base),
        };

        return $nullable ? $name . '?' : $name;
    }
}
