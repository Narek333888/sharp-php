<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\HashSet;
use SharpPhp\Runtime\System\Collections\Generic\List_;
use SharpPhp\Runtime\System\Collections\Generic\Queue;
use SharpPhp\Runtime\System\Collections\Generic\Stack;

/**
 * Conversions between SharpPHP collections and PHP arrays, where SharpPHP code and PHP code
 * (functions, Composer packages, Laravel) meet. The compiler inserts the calls:
 *
 *   in_array(x, names)                  in_array($x, Interop::toArray($names))
 *   Dictionary<string, int> d = php();  $d = Interop::toDictionary(php(), ['string', 'int'])
 *
 * PHP code can call them too, and can pass plain arrays to SharpPHP methods that take a List,
 * Dictionary or HashSet: the compiled methods convert them on entry.
 */
final class Interop
{
    /**
     * A PHP array: lists, sets, queues and stacks become lists; dictionaries become key => value
     * arrays, also when they are inside one another (Laravel's rules: ["tags"] = new List<string> { ... }).
     * Other values (arrays, objects, null) are returned as they are.
     */
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof Dictionary) {
            $array = [];
            foreach ($value as $pair) {
                $key = $pair->Key;
                $array[is_int($key) || is_string($key) ? $key : Op::str($key)] = self::nested($pair->Value);
            }

            return $array;
        }
        if ($value instanceof \Traversable) {
            return array_map(self::nested(...), iterator_to_array($value, false));
        }

        return $value;
    }

    /** An element of a collection given to PHP: a collection inside becomes an array too. */
    private static function nested(mixed $value): mixed
    {
        return $value instanceof Dictionary || $value instanceof List_ || $value instanceof HashSet
            || $value instanceof Queue || $value instanceof Stack || $value instanceof Sequence
            || $value instanceof System\Linq\IOrderedEnumerable ? self::toArray($value) : $value;
    }

    /**
     * @param array<mixed>|null $array
     * @return List_<mixed>|null
     */
    public static function toList(?array $array, ?string $of = null): ?List_
    {
        return $array === null ? null : new List_(self::values($array, $of), $of);
    }

    /**
     * @param array<mixed>|null $array key => value
     * @param array{0: string, 1: string}|null $of key and value type specs
     * @return Dictionary<mixed, mixed>|null
     */
    public static function toDictionary(?array $array, ?array $of = null): ?Dictionary
    {
        if ($array === null) {
            return null;
        }
        // PHP turns the key "7" into the integer 7; a Dictionary<string, ...> wants it back as a string.
        if (($of[0] ?? null) === 'string') {
            $pairs = [];
            foreach ($array as $key => $value) {
                $pairs[] = new System\Collections\Generic\KeyValuePair((string) $key, $value);
            }

            return new Dictionary($pairs, $of);
        }

        return new Dictionary($array, $of);
    }

    /**
     * @param array<mixed>|null $array
     * @return HashSet<mixed>|null
     */
    public static function toHashSet(?array $array, ?string $of = null): ?HashSet
    {
        return $array === null ? null : new HashSet(self::values($array, $of), $of);
    }

    /**
     * The values of the array; for a collection of strings, integers become strings again (PHP makes
     * the array key "7" the integer 7, so array_keys() returns it as one).
     *
     * @param array<mixed> $array
     * @return list<mixed>
     */
    private static function values(array $array, ?string $of): array
    {
        $values = array_values($array);

        return $of === 'string' ? array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $values) : $values;
    }
}
