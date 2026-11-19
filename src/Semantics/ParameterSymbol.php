<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * A parameter of a method or constructor: name, type, modifier (ref, out, params) and whether it may be omitted.
 */
final class ParameterSymbol
{
    public function __construct(
        public readonly string $name,
        public readonly Type $type,
        public readonly ?string $modifier = null,
        public readonly bool $optional = false,
        /** Takes a number: a char argument is passed as its code (int|float in PHP, or "@sharp-char-as-int $name"). */
        public readonly bool $numeric = false,
    ) {
    }

    /**
     * "int, string" for a parameter list, as in C# signatures.
     *
     * @param list<ParameterSymbol> $params
     */
    public static function describeTypes(array $params): string
    {
        return implode(', ', array_map(static fn (self $p): string => $p->type->describe(), $params));
    }
}
