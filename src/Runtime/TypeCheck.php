<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * Runtime checks for generic type arguments (List<int> rejects strings).
 */
final class TypeCheck
{
    public static function matches(mixed $value, ?string $spec): bool
    {
        if ($spec === null || $spec === 'mixed') {
            return true;
        }
        if (str_starts_with($spec, '?')) {
            if ($value === null) {
                return true;
            }
            $spec = substr($spec, 1);
        }
        if ($value === null) {
            // C# reference types (classes, string, arrays, delegates) hold null; value types
            // (numbers, bool, char, enums) do not.
            return !in_array($spec, ['int', 'float', 'bool', 'char'], true) && !enum_exists($spec);
        }

        return match ($spec) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'char' => is_string($value) && mb_strlen($value) === 1,
            'bool' => is_bool($value),
            'array' => is_array($value),
            'Closure' => $value instanceof \Closure,
            default => $value instanceof $spec,
        };
    }

    /** Applies implicit conversions C# would apply (int -> double). */
    public static function coerce(mixed $value, ?string $spec): mixed
    {
        if (is_int($value) && ($spec === 'float' || $spec === '?float')) {
            return (float) $value;
        }

        return $value;
    }

    public static function check(mixed $value, ?string $spec, string $collection): mixed
    {
        if (!self::matches($value, $spec)) {
            throw new System\ArgumentException(sprintf(
                'The value "%s" is not of type "%s" and cannot be used in this generic collection.',
                Op::str($value),
                TypeName::ofSpec((string) $spec),
            ), 'value');
        }

        return self::coerce($value, $spec);
    }
}
