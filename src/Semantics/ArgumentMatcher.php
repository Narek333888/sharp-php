<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

use SharpPhp\Syntax\Argument;

/**
 * Matches call arguments to parameters the way C# does: too many arguments, a missing
 * required argument or an unknown parameter name is an error. (PHP would silently drop
 * extra arguments to a userland function.)
 */
final class ArgumentMatcher
{
    /**
     * @param list<Argument> $args
     * @param list<ParameterSymbol> $params
     * @param string $name method (or type, for constructors) as written in messages
     * @param string $signature "Program.Add(int, int)", for the missing-argument message
     * @param bool $runtime the callee is part of the runtime library, which implements only some .NET overloads
     */
    public function violation(array $args, array $params, string $name, string $signature, bool $constructor = false, bool $runtime = false): ?Violation
    {
        $note = $runtime ? ' (the SharpPHP runtime library does not implement this .NET overload)' : '';
        $variadic = $params !== [] && $params[array_key_last($params)]->modifier === 'params';
        $positional = 0;
        $named = [];
        foreach ($args as $arg) {
            if ($arg->name === null) {
                $positional++;
                continue;
            }
            if (!$this->hasParameter($params, $arg->name)) {
                return new Violation('SP1739', "The best overload for '$name' does not have a parameter named '{$arg->name}'", $arg->value);
            }
            $named[$arg->name] = true;
        }

        if ($positional > count($params) && !$variadic) {
            $count = count($args);

            return $constructor
                ? new Violation('SP1729', "'$name' does not contain a constructor that takes $count arguments$note")
                : new Violation('SP1501', "No overload for method '$name' takes $count arguments$note");
        }

        foreach ($params as $i => $param) {
            if (!$param->optional && $param->modifier !== 'params' && $i >= $positional && !isset($named[$param->name])) {
                return new Violation('SP7036', "There is no argument given that corresponds to the required parameter '{$param->name}' of '$signature'$note");
            }
        }

        return null;
    }

    /** @param list<ParameterSymbol> $params */
    private function hasParameter(array $params, string $name): bool
    {
        foreach ($params as $param) {
            if ($param->name === $name) {
                return true;
            }
        }

        return false;
    }
}
