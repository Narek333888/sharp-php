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

    private static int $lastId = 0;

    /** Unique for the whole compilation (spl_object_id values are reused after scopes are freed). */
    public readonly int $id;

    public function __construct(
        public readonly ?Scope $parent = null,
        public readonly ?LambdaContext $lambda = null,
    ) {
        $this->id = ++self::$lastId;
    }

    public function declare(string $name, Type $type): void
    {
        $this->vars[$name] = $type;
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
