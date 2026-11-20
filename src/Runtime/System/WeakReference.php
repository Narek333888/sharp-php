<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.WeakReference<T> (and the non-generic System.WeakReference): a reference that does not keep
 * its target alive, on PHP's \WeakReference.
 *
 *   var cache = new WeakReference<Image>(image);
 *   if (cache.TryGetTarget(out var img)) { ... }
 *
 * @template T of object
 */
final class WeakReference
{
    /** @var \WeakReference<T>|null */
    private ?\WeakReference $reference = null;

    /** PHP keeps no reference to an object being freed, so this only records what was asked. */
    public private(set) bool $TrackResurrection;

    /** False once the target was freed. */
    public bool $IsAlive {
        get => $this->reference?->get() !== null;
    }

    /** @var T|null the target, or null once it was freed */
    public ?object $Target {
        get => $this->reference?->get();
        set {
            $this->SetTarget($value);
        }
    }

    /** @param T|null $target */
    public function __construct(?object $target, bool $trackResurrection = false)
    {
        $this->TrackResurrection = $trackResurrection;
        $this->SetTarget($target);
    }

    /**
     * @param-out T|null $target
     */
    public function TryGetTarget(mixed &$target): bool
    {
        $target = $this->reference?->get();

        return $target !== null;
    }

    /** @param T|null $target */
    public function SetTarget(?object $target): void
    {
        $this->reference = $target === null ? null : \WeakReference::create($target);
    }
}
