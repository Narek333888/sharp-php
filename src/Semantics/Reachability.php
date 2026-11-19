<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\BreakStmt;
use SharpPhp\Syntax\CatchClause;
use SharpPhp\Syntax\ContinueStmt;
use SharpPhp\Syntax\DoStmt;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\ForeachStmt;
use SharpPhp\Syntax\ForStmt;
use SharpPhp\Syntax\IfStmt;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\Stmt;
use SharpPhp\Syntax\SwitchLabel;
use SharpPhp\Syntax\SwitchSection;
use SharpPhp\Syntax\SwitchStmt;
use SharpPhp\Syntax\ThrowStmt;
use SharpPhp\Syntax\TryStmt;
use SharpPhp\Syntax\WhileStmt;
use SharpPhp\Syntax\YieldStmt;

/**
 * Reachability of statement end points (C# specification, "End points and reachability"),
 * used for "not all code paths return a value" and for definite assignment.
 *
 * Only the literal true counts as a constant condition. When in doubt an end point is
 * treated as unreachable, so valid code is never rejected.
 */
final class Reachability
{
    /** @param list<Stmt> $statements */
    public function statementsEndReachable(array $statements): bool
    {
        foreach ($statements as $statement) {
            if (!$this->endReachable($statement)) {
                return false;
            }
        }

        return true;
    }

    public function endReachable(Stmt $s): bool
    {
        return match (true) {
            $s instanceof Block => $this->statementsEndReachable($s->statements),
            $s instanceof ReturnStmt, $s instanceof ThrowStmt, $s instanceof BreakStmt, $s instanceof ContinueStmt => false,
            $s instanceof YieldStmt => $s->value !== null, // "yield break" ends the iterator
            $s instanceof IfStmt => $this->ifEndReachable($s),
            $s instanceof WhileStmt => !$this->isConstantTrue($s->condition) || $this->jumpsOut($s->body, BreakStmt::class),
            $s instanceof ForStmt => ($s->condition !== null && !$this->isConstantTrue($s->condition))
                || $this->jumpsOut($s->body, BreakStmt::class),
            $s instanceof DoStmt => (!$this->isConstantTrue($s->condition)
                    && ($this->endReachable($s->body) || $this->jumpsOut($s->body, ContinueStmt::class)))
                || $this->jumpsOut($s->body, BreakStmt::class),
            $s instanceof ForeachStmt => true,
            $s instanceof SwitchStmt => $this->switchEndReachable($s),
            $s instanceof TryStmt => $this->tryEndReachable($s),
            default => true,
        };
    }

    /** Whether control can leave a switch section: by reaching its end or through a break. */
    public function sectionExits(SwitchSection $section): bool
    {
        $block = new Block($section->statements);

        return $this->endReachable($block) || $this->jumpsOut($block, BreakStmt::class);
    }

    /** "default:", "case _:" and "case var x:" (without "when") match every value. */
    public function isCatchAll(SwitchLabel $label): bool
    {
        return $label->pattern === null
            || (in_array($label->pattern->kind, ['discard', 'var'], true) && $label->when === null);
    }

    /**
     * Whether $body contains a break/continue that leaves the enclosing loop or switch
     * (nested loops and switches have their own targets).
     *
     * @param class-string<BreakStmt|ContinueStmt> $jump
     */
    public function jumpsOut(Stmt $body, string $jump): bool
    {
        return match (true) {
            $body instanceof $jump => true,
            $body instanceof Block => $this->anyJumpsOut($body->statements, $jump),
            $body instanceof IfStmt => $this->jumpsOut($body->then, $jump) || ($body->else !== null && $this->jumpsOut($body->else, $jump)),
            $body instanceof TryStmt => $this->jumpsOut($body->body, $jump)
                || $this->anyJumpsOut(array_map(static fn (CatchClause $c): Block => $c->body, $body->catches), $jump)
                || ($body->finally !== null && $this->jumpsOut($body->finally, $jump)),
            // A continue inside a switch still targets the enclosing loop.
            $body instanceof SwitchStmt => $jump === ContinueStmt::class
                && $this->anyJumpsOut(array_map(static fn (SwitchSection $s): Block => new Block($s->statements), $body->sections), $jump),
            default => false,
        };
    }

    public function isConstantTrue(Expr $e): bool
    {
        return $e instanceof LiteralExpr && $e->kind === 'bool' && $e->value === true;
    }

    private function ifEndReachable(IfStmt $s): bool
    {
        if ($this->endReachable($s->then)) {
            return true;
        }
        if ($this->isConstantTrue($s->condition)) {
            return false; // the else branch (if any) never runs
        }

        return $s->else === null || $this->endReachable($s->else);
    }

    private function switchEndReachable(SwitchStmt $s): bool
    {
        $hasCatchAll = false;
        foreach ($s->sections as $section) {
            foreach ($section->labels as $label) {
                $hasCatchAll = $hasCatchAll || $this->isCatchAll($label);
            }
            if ($this->sectionExits($section)) {
                return true;
            }
        }

        return !$hasCatchAll;
    }

    private function tryEndReachable(TryStmt $s): bool
    {
        if ($s->finally !== null && !$this->endReachable($s->finally)) {
            return false;
        }
        if ($this->endReachable($s->body)) {
            return true;
        }
        foreach ($s->catches as $catch) {
            if ($this->endReachable($catch->body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Stmt> $statements
     * @param class-string<BreakStmt|ContinueStmt> $jump
     */
    private function anyJumpsOut(array $statements, string $jump): bool
    {
        foreach ($statements as $statement) {
            if ($this->jumpsOut($statement, $jump)) {
                return true;
            }
        }

        return false;
    }
}
