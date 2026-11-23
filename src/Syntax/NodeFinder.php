<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * Generic traversal over syntax trees.
 */
final class NodeFinder
{
    /**
     * True when $predicate matches $root or any descendant.
     * Lambdas are not entered unless $enterLambdas is true.
     *
     * @param callable(Node): bool $predicate
     */
    public static function contains(Node $root, callable $predicate, bool $enterLambdas = false): bool
    {
        if ($predicate($root)) {
            return true;
        }
        if (!$enterLambdas && $root instanceof LambdaExpr) {
            return false;
        }
        foreach (get_object_vars($root) as $value) {
            if ($value instanceof Node) {
                if (self::contains($value, $predicate, $enterLambdas)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::contains($item, $predicate, $enterLambdas)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Every node in $roots and their descendants that $predicate matches, lambdas included.
     *
     * @param list<Node|null> $roots
     * @param callable(Node): bool $predicate
     * @return list<Node>
     */
    public static function all(array $roots, callable $predicate): array
    {
        $found = [];
        $stack = array_values(array_filter($roots, static fn (?Node $n): bool => $n !== null));
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($predicate($node)) {
                $found[] = $node;
            }
            foreach (get_object_vars($node) as $value) {
                if ($value instanceof Node) {
                    $stack[] = $value;
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if ($item instanceof Node) {
                            $stack[] = $item;
                        }
                    }
                }
            }
        }

        return $found;
    }
}
