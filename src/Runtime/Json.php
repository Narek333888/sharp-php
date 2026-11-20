<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * JSON support for compiled objects: records and anonymous objects implement \JsonSerializable with
 * their public properties, so json_encode() and frameworks (Laravel turns a JsonSerializable controller
 * result into a JSON response) write them as JSON objects.
 */
final class Json
{
    /**
     * Public instance properties and their values: properties with get accessors too (json_encode()
     * alone skips them). In System.Text.Json's order: the class's own properties before inherited
     * ones, a record's positional properties (constructor parameters) first. An object, so that one
     * without properties is written {}.
     */
    public static function properties(object $value): \stdClass
    {
        $properties = new \stdClass();
        foreach (self::ordered((new \ReflectionObject($value))->getProperties(\ReflectionProperty::IS_PUBLIC)) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            // A set-only property has nothing to read; an unset typed property has no value yet.
            if ($property->isVirtual() ? !$property->hasHook(\PropertyHookType::Get) : !$property->isInitialized($value)) {
                continue;
            }
            $properties->{$property->getName()} = $property->getValue($value);
        }

        return $properties;
    }

    /**
     * @param list<\ReflectionProperty> $properties as reflection lists them, class by class
     * @return list<\ReflectionProperty> promoted (constructor) properties first within each class
     */
    private static function ordered(array $properties): array
    {
        $byClass = [];
        foreach ($properties as $property) {
            $byClass[$property->getDeclaringClass()->getName()][$property->isPromoted() ? 0 : 1][] = $property;
        }
        $result = [];
        foreach ($byClass as $groups) {
            array_push($result, ...($groups[0] ?? []), ...($groups[1] ?? []));
        }

        return $result;
    }
}
