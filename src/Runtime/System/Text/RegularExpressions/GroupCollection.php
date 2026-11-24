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

    /**
     * groups.ContainsKey("year"): whether the pattern has a group of that name (or number: "1"), also
     * one that captured nothing; false for every name on a failed match, whose Groups are empty.
     */
    public function ContainsKey(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    /** PHP's isset(groups[key]): whether the match has a group of that number or name, as ContainsKey. */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->groups[$offset]);
    }

    /**
     * groups[1] or groups["year"]: the group; an unknown number or name gives a group with Success
     * false and Value "", as in .NET.
     */
    public function offsetGet(mixed $offset): Group
    {
        return $this->groups[$offset] ?? new Group(false, '', 0, (string) $offset);
    }

    /** groups[key] = value: NotSupportedException, the collection is read-only. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \SharpPhp\Runtime\System\NotSupportedException('Collection is read-only.');
    }

    /** PHP's unset(groups[key]): NotSupportedException, the collection is read-only. */
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

    /** PHP's count(groups), which LINQ's Count() uses too: the number of groups, as Count (a named one counts once). */
    public function count(): int
    {
        return $this->Count;
    }
}
