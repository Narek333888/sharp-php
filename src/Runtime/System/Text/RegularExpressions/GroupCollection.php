<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text\RegularExpressions;

/**
 * System.Text.RegularExpressions.GroupCollection: match.Groups[1], match.Groups["year"].
 * Group 0 is the whole match. An unknown number or name gives a group with Success false.
 *
 * @implements \ArrayAccess<int|string, Group>
 * @implements \IteratorAggregate<int, Group>
 * @sharp-element Group
 */
final class GroupCollection implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /**
     * @internal
     * @param array<int|string, Group> $groups by number and by name
     */
    public function __construct(private readonly array $groups)
    {
    }

    /** @var int<0, max> */
    public int $Count {
        get => count(array_filter(array_keys($this->groups), 'is_int'));
    }

    public function ContainsKey(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->groups[$offset]);
    }

    public function offsetGet(mixed $offset): Group
    {
        return $this->groups[$offset] ?? new Group(false, '', 0, (string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \SharpPhp\Runtime\System\NotSupportedException('Collection is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \SharpPhp\Runtime\System\NotSupportedException('Collection is read-only.');
    }

    /** @return \Iterator<int, Group> */
    public function getIterator(): \Iterator
    {
        foreach ($this->groups as $key => $group) {
            if (is_int($key)) {
                yield $group;
            }
        }
    }

    public function count(): int
    {
        return $this->Count;
    }
}
