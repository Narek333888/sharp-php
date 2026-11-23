<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.Delegate: delegates are PHP closures; combining them makes a closure that calls each in turn
 * (and returns the last result), as C#'s multicast delegates do.
 *
 *   Action log = () => Console.Write("a");
 *   log += () => Console.Write("b");          // Delegate.Combine
 *   log();                                    // ab
 *   log -= first;                             // Delegate.Remove (the last occurrence)
 *   clicked?.Invoke();                        // null when every handler was removed
 */
final class Delegate
{
    /** @var \WeakMap<\Closure, list<\Closure>>|null the parts of each combined delegate */
    private static ?\WeakMap $lists = null;

    /** a + b: null when both are null. */
    public static function Combine(?\Closure $a, ?\Closure $b): ?\Closure
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return self::of([...self::GetInvocationList($a), ...self::GetInvocationList($b)]);
    }

    /** a - b: a without the last occurrence of b's list of delegates; null when nothing is left. */
    public static function Remove(?\Closure $source, ?\Closure $value): ?\Closure
    {
        if ($source === null || $value === null) {
            return $source;
        }
        $list = self::GetInvocationList($source);
        $remove = self::GetInvocationList($value);
        $count = count($remove);
        for ($start = count($list) - $count; $start >= 0; $start--) {
            $matches = true;
            for ($i = 0; $i < $count && $matches; $i++) {
                $matches = self::same($list[$start + $i], $remove[$i]);
            }
            if ($matches) {
                array_splice($list, $start, $count);

                return self::of($list);
            }
        }

        return $source;
    }

    /**
     * The delegates a (combined) delegate calls, in order.
     *
     * @return list<\Closure>
     */
    public static function GetInvocationList(\Closure $delegate): array
    {
        return self::$lists[$delegate] ?? [$delegate];
    }

    /** @param list<\Closure> $list */
    private static function of(array $list): ?\Closure
    {
        if ($list === []) {
            return null;
        }
        if (count($list) === 1) {
            return $list[0];
        }
        $combined = static function (mixed ...$args) use ($list): mixed {
            $result = null;
            foreach ($list as $delegate) {
                $result = $delegate(...$args);
            }

            return $result;
        };
        self::$lists ??= new \WeakMap();
        self::$lists[$combined] = $list;

        return $combined;
    }

    /**
     * Two delegates are equal when they are the same closure or call the same method on the same
     * object: button.Clicked -= OnClick removes what button.Clicked += OnClick added, although each
     * mention of OnClick makes a new closure.
     */
    private static function same(\Closure $a, \Closure $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $fa = new \ReflectionFunction($a);
        $fb = new \ReflectionFunction($b);
        if ($fa->isClosure() && str_contains($fa->getName(), '{closure')) {
            return false; // a lambda: only the same one is equal
        }

        return $fa->getName() === $fb->getName()
            && $fa->getClosureThis() === $fb->getClosureThis()
            && $fa->getClosureScopeClass()?->getName() === $fb->getClosureScopeClass()?->getName();
    }
}
