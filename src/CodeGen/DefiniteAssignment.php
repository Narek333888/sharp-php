<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

/**
 * Definite assignment of local variables ("int x; Console.WriteLine(x);" does not compile in C#).
 *
 * The emitter walks statements in order: it saves the state before a branch, restores it for
 * each alternative and merges the states of the alternatives that can complete normally.
 * A state is the set of locals that are not definitely assigned, keyed "scopeId:name".
 */
final class DefiniteAssignment
{
    /** @var array<string, true> */
    private array $unassigned = [];

    public function declare(Scope $scope, string $name, bool $assigned): void
    {
        if ($assigned) {
            unset($this->unassigned[self::key($scope, $name)]);
        } else {
            $this->unassigned[self::key($scope, $name)] = true;
        }
    }

    /** Marks the local $name, as seen from $scope, as assigned. */
    public function assign(Scope $scope, string $name): void
    {
        $declaring = $scope->declaringScope($name);
        if ($declaring !== null) {
            unset($this->unassigned[self::key($declaring, $name)]);
        }
    }

    public function isUnassigned(Scope $scope, string $name): bool
    {
        $declaring = $scope->declaringScope($name);

        return $declaring !== null && isset($this->unassigned[self::key($declaring, $name)]);
    }

    /** @return array<string, true> */
    public function save(): array
    {
        return $this->unassigned;
    }

    /** @param array<string, true> $state */
    public function restore(array $state): void
    {
        $this->unassigned = $state;
    }

    /**
     * After a branching statement a local is unassigned if it is unassigned at the end of any
     * alternative that can complete normally. No such alternative: the code after the statement
     * is unreachable, where C# treats every local as assigned.
     *
     * @param list<array<string, true>> $alternatives
     */
    public function merge(array $alternatives): void
    {
        $this->unassigned = $alternatives === [] ? [] : array_merge(...$alternatives);
    }

    /**
     * After try/finally: whatever the finally block assigned is assigned afterwards, however
     * the try block ended. Call with the state before the finally block; the current state is
     * the one at its end.
     *
     * @param array<string, true> $beforeFinally
     * @param array<string, true> $afterTry merged state of the try block and its catch clauses
     */
    public function applyFinally(array $beforeFinally, array $afterTry): void
    {
        $assignedInFinally = array_diff_key($beforeFinally, $this->unassigned);
        $this->unassigned = array_diff_key($afterTry, $assignedInFinally);
    }

    private static function key(Scope $scope, string $name): string
    {
        return $scope->id . ':' . $name;
    }
}
