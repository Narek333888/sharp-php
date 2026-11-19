<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\Semantics\Type;

/**
 * State of the method/accessor/lambda body being emitted.
 */
final class FunctionContext
{
    /** @var list<'loop'|'switch'> */
    public array $breakables = [];

    /** @var list<string> catch variables, innermost last (for "throw;") */
    public array $catchVariables = [];

    /** @var list<string> out parameters, which must be assigned before the method returns */
    public array $outParams = [];

    private int $temp = 0;

    /**
     * @param array<string, Type> $typeParams
     */
    public function __construct(
        public readonly bool $isStatic,
        public readonly Type $returnType,
        public readonly array $typeParams = [],
        public readonly ?FunctionContext $parent = null,
        /** Instance or static constructor: readonly members of the class may be assigned. */
        public readonly bool $isConstructor = false,
    ) {
    }

    public function temp(string $hint = 't'): string
    {
        $root = $this;
        while ($root->parent !== null) {
            $root = $root->parent;
        }

        return '$__' . $hint . ++$root->temp;
    }
}
