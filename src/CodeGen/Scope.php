<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\Semantics\Type;

/**
 * Lexical scope for local variables. A scope created for a lambda marks a capture boundary:
 * variables found beyond it are recorded as captures of that lambda.
 */
final class Scope
{
    /** @var array<string, Type> */
    private array $vars = [];

    /** @var array<string, 'parameter'|'const'|'foreach'|'using'> what a variable is, when it is not a plain local */
    private array $kinds = [];

    /** @var array<string, true> the names of the locals this block declares further on (C#'s scope is the block) */
    private array $later = [];

    private static int $lastId = 0;

    /** Unique for the whole compilation (spl_object_id values are reused after scopes are freed). */
    public readonly int $id;

    public function __construct(
        public readonly ?Scope $parent = null,
        public readonly ?LambdaContext $lambda = null,
    ) {
        $this->id = ++self::$lastId;
    }

    /** @param 'parameter'|'const'|'foreach'|'using'|null $kind */
    public function declare(string $name, Type $type, ?string $kind = null): void
    {
        $this->vars[$name] = $type;
        unset($this->later[$name]);
        if ($kind !== null) {
            $this->kinds[$name] = $kind;
        }
    }

    /** A local that the block declares later (C#'s CS0841 for a use before it, CS0136 for an inner one). */
    public function declareLater(string $name): void
    {
        if (!isset($this->vars[$name])) {
            $this->later[$name] = true;
        }
    }

    /** @return 'parameter'|'const'|'foreach'|'using'|null what the variable $name visible here is */
    public function kindOf(string $name): ?string
    {
        $scope = $this->declaringScope($name);

        return $scope?->kinds[$name] ?? null;
    }

    /** True when an enclosing block declares $name further on (it is not declared yet). */
    public function isDeclaredLater(string $name): bool
    {
        for ($scope = $this; $scope !== null; $scope = $scope->parent) {
            if (isset($scope->vars[$name])) {
                return false;
            }
            if (isset($scope->later[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a local or parameter named $name of an enclosing scope of the same function (declared
     * already or later in its block) would be hidden by one declared here: C#'s CS0136. Lambdas and
     * local functions may hide them (C# 8).
     */
    public function hidesEnclosing(string $name): bool
    {
        if ($this->lambda !== null) {
            return false;
        }
        for ($scope = $this->parent; $scope !== null; $scope = $scope->parent) {
            if (isset($scope->vars[$name]) || isset($scope->later[$name])) {
                return true;
            }
            if ($scope->lambda !== null) {
                return false;
            }
        }

        return false;
    }

    /** @return 'parameter'|'const'|'foreach'|'using'|null what this scope's own $name is */
    public function ownKind(string $name): ?string
    {
        return $this->kinds[$name] ?? null;
    }

    public function declaresLocally(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    /**
     * Every variable visible here, the innermost declaration of each name first.
     *
     * @return array<string, Type>
     */
    public function visible(): array
    {
        $vars = [];
        for ($scope = $this; $scope !== null; $scope = $scope->parent) {
            $vars += $scope->vars;
        }

        return $vars;
    }

    /** The scope that declares $name (this one or an enclosing one). */
    public function declaringScope(string $name): ?Scope
    {
        for ($scope = $this; $scope !== null; $scope = $scope->parent) {
            if (isset($scope->vars[$name])) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * @return array{0: Type, 1: list<LambdaContext>}|null type and lambdas crossed on the way
     */
    public function lookup(string $name): ?array
    {
        $crossed = [];
        for ($scope = $this; $scope !== null; $scope = $scope->parent) {
            if (isset($scope->vars[$name])) {
                return [$scope->vars[$name], $crossed];
            }
            if ($scope->lambda !== null) {
                $crossed[] = $scope->lambda;
            }
        }

        return null;
    }
}
