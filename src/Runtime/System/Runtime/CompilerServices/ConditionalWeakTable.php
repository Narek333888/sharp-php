<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Runtime\CompilerServices;

use SharpPhp\Runtime\System\ArgumentException;

/**
 * System.Runtime.CompilerServices.ConditionalWeakTable<TKey, TValue>: values attached to objects
 * without keeping the objects alive (an entry goes when its key is freed), on PHP's \WeakMap.
 *
 * @template TKey of object
 * @template TValue
 */
final class ConditionalWeakTable
{
    /** @var \WeakMap<TKey, TValue> */
    private \WeakMap $map;

    public function __construct()
    {
        $this->map = new \WeakMap();
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function Add(object $key, mixed $value): void
    {
        if (isset($this->map[$key])) {
            throw new ArgumentException('An entry with the same key already exists.');
        }
        $this->map[$key] = $value;
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function AddOrUpdate(object $key, mixed $value): void
    {
        $this->map[$key] = $value;
    }

    /**
     * @param TKey $key
     * @param-out TValue|null $value null (the default) when there is no entry
     */
    public function TryGetValue(object $key, mixed &$value): bool
    {
        if (!$this->map->offsetExists($key)) {
            $value = null;

            return false;
        }
        $value = $this->map[$key];

        return true;
    }

    /**
     * The value for $key, created by $createValueCallback(key) when there is none.
     *
     * @param TKey $key
     * @param \Closure(TKey): TValue $createValueCallback
     * @return TValue
     */
    public function GetValue(object $key, \Closure $createValueCallback): mixed
    {
        if (!$this->map->offsetExists($key)) {
            $this->map[$key] = $createValueCallback($key);
        }

        return $this->map[$key];
    }

    /** @param TKey $key */
    public function Remove(object $key): bool
    {
        if (!$this->map->offsetExists($key)) {
            return false;
        }
        unset($this->map[$key]);

        return true;
    }

    public function Clear(): void
    {
        $this->map = new \WeakMap();
    }
}
