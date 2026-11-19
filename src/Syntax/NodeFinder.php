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
}
