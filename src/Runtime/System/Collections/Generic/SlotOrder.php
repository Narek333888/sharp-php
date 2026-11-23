<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

use SharpPhp\Runtime\System\InvalidOperationException;

/**
 * The order in which Dictionary and HashSet enumerate, as .NET's: entries sit in slots, a removed
 * entry frees its slot, and the next new entry takes the slot freed last (so after Remove(2) and
 * Add(4), {1, 2, 3} enumerates as 1, 4, 3). While entries are only removed from the end, the slots
 * are the order of insertion and nothing is tracked; the first removal of another entry starts
 * tracking them.
 *
 * Also the version that makes adding a new entry during foreach throw, as in .NET (removing entries,
 * clearing and changing a value do not).
 *
 * @internal
 */
trait SlotOrder
{
    /** @var array<int, string|null>|null the hash in each slot (null: free); null while slots follow insertion order */
    private ?array $slots = null;

    /** @var array<string, int> */
    private array $slotOf = [];

    /** @var list<int> free slots, the one freed last at the end */
    private array $freeSlots = [];

    private int $version = 0;

    /** Counts removals, so that an enumeration knows when it must skip removed entries. */
    private int $removals = 0;

    /** After a new entry was stored under $hash, while slots are tracked (the caller counts the version). */
    private function slotAdded(string $hash): void
    {
        $this->slots ??= [];
        $slots = &$this->slots; // a reference: no copy of the array
        $slot = $this->freeSlots === [] ? count($slots) : array_pop($this->freeSlots);
        $slots[$slot] = $hash;
        $this->slotOf[$hash] = $slot;
    }

    /**
     * Before the entry stored under $hash is removed.
     *
     * @param array<string, mixed> $storage the entries by hash, in insertion order
     */
    private function slotRemoved(string $hash, array $storage): void
    {
        $this->removals++;
        if ($this->slots === null) {
            if (array_key_last($storage) === $hash) {
                return; // the last slot: the next entry takes it, at the end anyway
            }
            /** @var list<string> $hashes */
            $hashes = array_keys($storage);
            $this->slots = $hashes;
            $this->slotOf = array_flip($hashes);
        }
        $slot = $this->slotOf[$hash];
        $slots = &$this->slots; // a reference: no copy of the array
        $slots[$slot] = null;
        unset($this->slotOf[$hash]);
        $this->freeSlots[] = $slot;
    }

    private function slotsCleared(): void
    {
        $this->removals++;
        $this->slots = null;
        $this->slotOf = [];
        $this->freeSlots = [];
    }

    /**
     * The hashes in slot order.
     *
     * @param array<string, mixed> $storage
     * @return list<string>
     */
    private function orderedHashes(array $storage): array
    {
        if ($this->slots === null) {
            /** @var list<string> $hashes the hashes ("i:5", "s:a", ...) are never numeric, so they stay strings */
            $hashes = array_keys($storage);

            return $hashes;
        }
        // Slots are appended in order and reused in place, so the array is in slot order.
        return array_values(array_filter($this->slots, static fn (?string $h): bool => $h !== null));
    }

    private function assertVersion(int $version): void
    {
        if ($version !== $this->version) {
            throw new InvalidOperationException('Collection was modified; enumeration operation may not execute.');
        }
    }
}
