<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\Json;

use SharpPhp\Runtime\AnonymousObject;
use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\Collections\Generic\Dictionary;
use SharpPhp\Runtime\System\Collections\Generic\KeyValuePair;
use SharpPhp\Runtime\System\InvalidOperationException;
use SharpPhp\Runtime\System\NotSupportedException;

/**
 * System.Text.Json.JsonSerializer
 *
 *   string json = JsonSerializer.Serialize(new { Id = 1, Tags = new List<string> { "a" } });
 *   // {"Id":1,"Tags":["a"]}
 *
 * Objects are written with their public properties, lists and arrays as JSON arrays,
 * dictionaries as JSON objects, enums as numbers and dates in ISO 8601, as System.Text.Json does.
 */
final class JsonSerializer
{
    private const MAX_DEPTH = 64;

    public static function Serialize(mixed $value, ?JsonSerializerOptions $options = null): string
    {
        $options ??= new JsonSerializerOptions();
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($options->WriteIndented) {
            $flags |= JSON_PRETTY_PRINT;
        }
        try {
            $json = json_encode(self::toJson($value, $options->PropertyNamingPolicy, 0), $flags);
        } catch (\JsonException $e) {
            throw new InvalidOperationException('The value cannot be written as JSON: ' . $e->getMessage(), $e);
        }

        // PHP indents with 4 spaces, System.Text.Json with 2.
        return $options->WriteIndented ? (preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json) ?? $json) : $json;
    }

    /** The value as plain PHP data that json_encode() writes the way System.Text.Json does. */
    private static function toJson(mixed $value, ?string $naming, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidOperationException('A possible object cycle was detected. This can either be due to a cycle or if the object depth is larger than the maximum allowed depth of ' . self::MAX_DEPTH . '.');
        }
        $depth++;

        switch (true) {
            case $value === null || is_bool($value) || is_int($value) || is_string($value):
                return $value;
            case is_float($value):
                if (is_nan($value) || is_infinite($value)) {
                    throw new ArgumentException('.NET number values such as positive and negative infinity cannot be written as valid JSON.');
                }

                return $value;
            case $value instanceof \BackedEnum:
                return $value->value;
            case $value instanceof \UnitEnum:
                return $value->name;
            case $value instanceof \DateTimeInterface:
                return $value->format('Y-m-d\TH:i:sP');
            case $value instanceof \Closure:
                throw new NotSupportedException('Serialization and deserialization of delegates is not supported.');
            case $value instanceof \JsonSerializable:
                return self::toJson($value->jsonSerialize(), $naming, $depth);
            case $value instanceof KeyValuePair:
                $object = new \stdClass();
                $object->{JsonNamingPolicy::apply($naming, 'Key')} = self::toJson($value->Key, $naming, $depth);
                $object->{JsonNamingPolicy::apply($naming, 'Value')} = self::toJson($value->Value, $naming, $depth);

                return $object;
            case $value instanceof Dictionary:
                // Dictionary keys are written as they are (DictionaryKeyPolicy is not the naming policy).
                $object = new \stdClass();
                foreach ($value as $pair) {
                    $object->{Op::str($pair->Key)} = self::toJson($pair->Value, $naming, $depth);
                }

                return $object;
            case is_array($value) && !array_is_list($value):
                $object = new \stdClass();
                foreach ($value as $key => $item) {
                    $object->{(string) $key} = self::toJson($item, $naming, $depth);
                }

                return $object;
            case is_iterable($value):
                $list = [];
                foreach ($value as $item) {
                    $list[] = self::toJson($item, $naming, $depth);
                }

                return $list;
            case $value instanceof AnonymousObject || $value instanceof \stdClass:
                $object = new \stdClass();
                foreach (get_object_vars($value) as $name => $item) {
                    $object->{JsonNamingPolicy::apply($naming, $name)} = self::toJson($item, $naming, $depth);
                }

                return $object;
            case is_object($value):
                return self::objectToJson($value, $naming, $depth);
            default:
                throw new NotSupportedException('The value cannot be written as JSON.');
        }
    }

    /** Public instance properties, in declaration order (properties with get accessors too). */
    private static function objectToJson(object $value, ?string $naming, int $depth): \stdClass
    {
        $object = new \stdClass();
        foreach ((new \ReflectionObject($value))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            // A set-only property has nothing to read; an unset typed property has no value yet.
            if ($property->isVirtual() ? !$property->hasHook(\PropertyHookType::Get) : !$property->isInitialized($value)) {
                continue;
            }
            $object->{JsonNamingPolicy::apply($naming, $property->getName())} = self::toJson($property->getValue($value), $naming, $depth);
        }

        return $object;
    }
}
