<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

use SharpPhp\Runtime\Op;
use SharpPhp\Runtime\System\Collections\Generic\KeyValuePair;
use SharpPhp\Runtime\System\Collections\Generic\List_;
use SharpPhp\Runtime\System\NotSupportedException;

/**
 * System.Web.ParameterCollection: the read-only string values of a request (query string, form
 * fields, route values, headers, cookies).
 *
 * As in ASP.NET Core, keys are case-insensitive and a missing key reads as null:
 *
 *   string? page = req.Query["page"];
 *   int size = int.Parse(req.Query["size"] ?? "20");
 *
 * A key given several times (?tag=a&tag=b, or ?tag[]=a&tag[]=b) has all its values in GetValues().
 *
 * @sharp-element KeyValuePair<string, string>
 *
 * @implements \ArrayAccess<string, string|null>
 * @implements \IteratorAggregate<int, KeyValuePair<string, string>>
 */
final class ParameterCollection implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var array<string, array{0: string, 1: list<string>}> lowercase key => [key as given, values] */
    private array $items = [];

    public int $Count {
        get => count($this->items);
    }

    /** @var List_<string> */
    public List_ $Keys {
        get => new List_(array_values(array_map(static fn (array $item): string => $item[0], $this->items)), of: 'string');
    }

    /**
     * @param iterable<mixed, mixed> $values key => value (a value may be a list of values), or KeyValuePairs
     */
    public function __construct(iterable $values = [])
    {
        foreach ($values as $key => $value) {
            if ($value instanceof KeyValuePair) {
                [$key, $value] = [$value->Key, $value->Value];
            }
            $this->add(Op::str($key), $value);
        }
    }

    /**
     * The values of a query string ("a=1&tag=x&tag=y", with or without "?"). Unlike PHP's $_GET,
     * a key given several times keeps every value; "tag[]=x" is read as "tag".
     */
    public static function FromQueryString(string $query): self
    {
        $values = [];
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (str_ends_with($key, '[]')) {
                $key = substr($key, 0, -2);
            }
            $values[] = new KeyValuePair($key, urldecode($value));
        }

        return new self($values);
    }

    /** @param string $key */
    public function offsetGet(mixed $key): ?string
    {
        return $this->items[strtolower(Op::str($key))][1][0] ?? null;
    }

    /** @param string $key */
    public function offsetExists(mixed $key): bool
    {
        return isset($this->items[strtolower(Op::str($key))]);
    }

    /** req.Query["key"] = value: NotSupportedException, the collection is read-only. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new NotSupportedException('Collection is read-only.');
    }

    /** PHP's unset(req.Query["key"]): NotSupportedException, the collection is read-only. */
    public function offsetUnset(mixed $offset): void
    {
        throw new NotSupportedException('Collection is read-only.');
    }

    /** req.Query.ContainsKey(key): whether the key is there, ignoring case. */
    public function ContainsKey(string $key): bool
    {
        return isset($this->items[strtolower($key)]);
    }

    /** @param-out string $value */
    public function TryGetValue(string $key, mixed &$value): bool
    {
        $found = $this->offsetGet($key);
        $value = $found ?? '';

        return $found !== null;
    }

    /** @return List_<string> every value of the key (an empty list when it is missing) */
    public function GetValues(string $key): List_
    {
        return new List_($this->items[strtolower($key)][1] ?? [], of: 'string');
    }

    /**
     * PHP's count(collection), which LINQ's Count() uses too: the number of keys, as Count (a key
     * given several times counts once).
     */
    public function count(): int
    {
        return count($this->items);
    }

    /** @return \Generator<int, KeyValuePair<string, string>> */
    public function getIterator(): \Generator
    {
        foreach ($this->items as [$key, $values]) {
            yield new KeyValuePair($key, $values[0] ?? '');
        }
    }

    /**
     * query.ToString(): "key=value&key=value", every value of a key in turn, the keys as first
     * given; not URL-encoded.
     */
    public function ToString(): string
    {
        $parts = [];
        foreach ($this->items as [$key, $values]) {
            foreach ($values as $value) {
                $parts[] = $key . '=' . $value;
            }
        }

        return implode('&', $parts);
    }

    private function add(string $key, mixed $value): void
    {
        $values = [];
        foreach (is_iterable($value) ? $value : [$value] as $item) {
            if ($item !== null && !is_iterable($item)) {
                $values[] = Op::str($item);
            }
        }
        $lower = strtolower($key);
        if (isset($this->items[$lower])) {
            array_push($this->items[$lower][1], ...$values);
        } else {
            $this->items[$lower] = [$key, $values];
        }
    }
}
