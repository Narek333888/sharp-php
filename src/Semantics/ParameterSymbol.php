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
        /** @var array{0: string, 1: ?string}|null see Parameter::$callerInfo */
        public readonly ?array $callerInfo = null,
        /**
         * "@sharp-typed-numbers $name": the method writes the argument as text, which for a double, float
         * or decimal depends on its C# type: it gets the number's text, or, for a params parameter
         * (composite format arguments, which "{0:F2}" formats), the number with its type (a TypedNumber).
         * 'elements': also the elements of a sequence, as text (string.Join, string.Concat).
         *
         * @var 'value'|'elements'|null
         */
        public readonly ?string $typedNumbers = null,
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
