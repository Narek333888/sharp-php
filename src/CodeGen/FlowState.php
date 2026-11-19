<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

/**
 * Control flow at the point being emitted: whether it is reachable, and which locals are not
 * definitely assigned there (C# "definite assignment": int x; Console.WriteLine(x); is an error).
 *
 * The emitter walks statements in order. Around a branch it saves the state, restores it for
 * each alternative and merges the alternatives' end states; break and continue record their
 * state on the loop or switch they leave. A state is the set of unassigned locals, keyed
 * "scopeId:name", or null when the point is unreachable (where C# treats every local as
 * assigned and nothing needs to return).
 */
final class FlowState
{
    /** @var array<string, true>|null */
    private ?array $unassigned = [];

    /**
     * Enclosing loops and switches, innermost last, with the states recorded by the jumps that target them.
     *
     * @var list<array{kind: 'loop'|'switch', breaks: list<array<string, true>>, continues: list<array<string, true>>}>
     */
    private array $targets = [];

    public function isReachable(): bool
    {
        return $this->unassigned !== null;
    }

    /** After return, throw or yield break. */
    public function markUnreachable(): void
    {
        $this->unassigned = null;
    }

    public function declare(Scope $scope, string $name, bool $assigned): void
    {
        if ($this->unassigned === null) {
            return;
        }
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
        if ($declaring !== null && $this->unassigned !== null) {
            unset($this->unassigned[self::key($declaring, $name)]);
        }
    }

    public function isUnassigned(Scope $scope, string $name): bool
    {
        $declaring = $scope->declaringScope($name);

        return $declaring !== null && $this->unassigned !== null && isset($this->unassigned[self::key($declaring, $name)]);
    }

    /** @return array<string, true>|null */
    public function save(): ?array
    {
        return $this->unassigned;
    }

    /** @param array<string, true>|null $state */
    public function restore(?array $state): void
    {
        $this->unassigned = $state;
    }

    /**
     * Joins the end states of alternative paths: a local is unassigned if it is unassigned on any
     * reachable path; with no reachable path the point after them is unreachable.
     *
     * @param list<array<string, true>|null> $states
     */
    public function merge(array $states): void
    {
        $reachable = array_values(array_filter($states, static fn (?array $s): bool => $s !== null));
        $this->unassigned = $reachable === [] ? null : array_merge(...$reachable);
    }

    /** @param 'loop'|'switch' $kind */
    public function enterJumpTarget(string $kind): void
    {
        $this->targets[] = ['kind' => $kind, 'breaks' => [], 'continues' => []];
    }

    /**
     * Leaves the innermost loop or switch.
     *
     * @return array{breaks: list<array<string, true>>, continues: list<array<string, true>>} states recorded by its jumps
     */
    public function exitJumpTarget(): array
    {
        $target = array_pop($this->targets);
        if ($target === null) {
            throw new \LogicException('exitJumpTarget() without enterJumpTarget()');
        }

        return ['breaks' => $target['breaks'], 'continues' => $target['continues']];
    }

    public function jumpBreak(): void
    {
        $this->jump('breaks', count($this->targets) - 1);
    }

    /** "continue" targets the innermost loop, even from inside a switch. */
    public function jumpContinue(): void
    {
        for ($i = count($this->targets) - 1; $i >= 0; $i--) {
            if ($this->targets[$i]['kind'] === 'loop') {
                $this->jump('continues', $i);

                return;
            }
        }
        $this->unassigned = null;
    }

    /**
     * After try/finally: whatever the finally block assigned is assigned afterwards, however the
     * try block ended. Call when the current state is the one at the end of the finally block.
     *
     * @param array<string, true>|null $beforeFinally state the finally block started with
     * @param array<string, true>|null $afterTry merged end state of the try block and its catch clauses
     */
    public function applyFinally(?array $beforeFinally, ?array $afterTry): void
    {
        if ($this->unassigned === null || $afterTry === null || $beforeFinally === null) {
            $this->unassigned = null;

            return;
        }
        $assignedInFinally = array_diff_key($beforeFinally, $this->unassigned);
        $this->unassigned = array_diff_key($afterTry, $assignedInFinally);
    }

    /**
     * Runs $body as a new method or accessor body: reachable, nothing unassigned, no enclosing
     * loops. The outer state is restored afterwards.
     *
     * @template R
     * @param callable(): R $body
     * @return R
     */
    public function inFunction(callable $body): mixed
    {
        [$unassigned, $targets] = [$this->unassigned, $this->targets];
        $this->unassigned = [];
        $this->targets = [];
        try {
            return $body();
        } finally {
            [$this->unassigned, $this->targets] = [$unassigned, $targets];
        }
    }

    /** @param 'breaks'|'continues' $kind */
    private function jump(string $kind, int $target): void
    {
        if ($this->unassigned !== null && isset($this->targets[$target])) {
            $this->targets[$target][$kind][] = $this->unassigned;
        }
        $this->unassigned = null;
    }

    private static function key(Scope $scope, string $name): string
    {
        return $scope->id . ':' . $name;
    }
}
