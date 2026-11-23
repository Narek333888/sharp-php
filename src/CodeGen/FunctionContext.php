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

    /** An async method or lambda: await may be used, returnType is the T of Task<T> (void for Task). */
    public bool $isAsync = false;

    /**
     * The variables and parameters this method (not a lambda) assigns after their declaration,
     * lambdas included: a lambda captures them by reference, so it sees later changes as in C#.
     * Null when the body was not analysed (then only the variables a lambda writes are by reference).
     *
     * @var array<string, true>|null
     */
    public ?array $reassigned = null;

    /** @var array<string, true> the names used inside the method's lambdas */
    public array $capturedByLambdas = [];

    /** The local function this method was compiled from (see Parser::tryParseLocalFunction()). */
    public ?\SharpPhp\Syntax\MethodDecl $localFunction = null;

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

    public function root(): self
    {
        $root = $this;
        while ($root->parent !== null) {
            $root = $root->parent;
        }

        return $root;
    }

    public function temp(string $hint = 't'): string
    {
        $root = $this->root();

        return '$__' . $hint . ++$root->temp;
    }
}
