<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\Json;

use SharpPhp\Runtime\AnonymousObject;
use SharpPhp\Runtime\Json;
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

    /**
     * JsonSerializer.Serialize(value) or Serialize(value, options): the value as JSON text,
     * compact, or indented by 2 spaces with WriteIndented; PropertyNamingPolicy renames properties
     * (not dictionary keys). InvalidOperationException for an object cycle (nesting deeper than
     * 64), ArgumentException for NaN and infinity, NotSupportedException for a delegate.
     */
    public static function Serialize(mixed $value, ?JsonSerializerOptions $options = null): string
    {
        $options ??= new JsonSerializerOptions();

        return self::write(self::toJson($value, $options->PropertyNamingPolicy, 0), $options->WriteIndented, 0);
    }

    /**
     * The JSON text, as System.Text.Json writes it: numbers as .NET formats them, strings escaped as
     * its default encoder does, indented by 2 spaces.
     */
    private static function write(mixed $data, bool $indented, int $level): string
    {
        $newline = $indented ? "\n" . str_repeat('  ', $level + 1) : '';
        $close = $indented ? "\n" . str_repeat('  ', $level) : '';

        return match (true) {
            $data === null => 'null',
            is_bool($data) => $data ? 'true' : 'false',
            is_int($data) => (string) $data,
            is_float($data) => Op::shortest($data),
            is_string($data) => self::quote($data),
            is_array($data) => $data === [] ? '[]' : '[' . $newline . implode(',' . $newline, array_map(static fn (mixed $item): string => self::write($item, $indented, $level + 1), $data)) . $close . ']',
            $data instanceof \stdClass => ($members = get_object_vars($data)) === [] ? '{}' : '{' . $newline . implode(',' . $newline, array_map(
                static fn (int|string $key, mixed $item): string => self::quote((string) $key) . ($indented ? ': ' : ':') . self::write($item, $indented, $level + 1),
                array_keys($members),
                $members,
            )) . $close . '}',
            default => throw new NotSupportedException('The value cannot be written as JSON.'),
        };
    }

    /**
     * A JSON string as System.Text.Json's default encoder writes it: quotes, HTML-sensitive characters
     * (< > & ' + `) and everything outside ASCII as \uXXXX (in UTF-16), control characters as \n, \t, ...
     */
    private static function quote(string $s): string
    {
        $out = '"';
        foreach (mb_str_split($s) as $char) {
            $code = mb_ord($char);
            $out .= match (true) {
                $char === '\\' => '\\\\',
                $char === "\n" => '\\n',
                $char === "\r" => '\\r',
                $char === "\t" => '\\t',
                $char === "\x08" => '\\b',
                $char === "\x0C" => '\\f',
                $code >= 0x20 && $code < 0x7F && !str_contains('"<>&\'+`', $char) => $char,
                $code > 0xFFFF => sprintf('\\u%04X\\u%04X', 0xD800 + (($code - 0x10000) >> 10), 0xDC00 + (($code - 0x10000) & 0x3FF)),
                default => sprintf('\\u%04X', $code),
            };
        }

        return $out . '"';
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
            case $value instanceof \JsonSerializable && !$value instanceof \Traversable:
                return self::toJson($value->jsonSerialize(), $naming, $depth);
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
        foreach (get_object_vars(Json::properties($value)) as $name => $item) {
            $object->{JsonNamingPolicy::apply($naming, $name)} = self::toJson($item, $naming, $depth);
        }

        return $object;
    }
}
