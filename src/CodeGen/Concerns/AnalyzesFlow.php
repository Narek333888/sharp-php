<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\BreakStmt;
use SharpPhp\Syntax\ContinueStmt;
use SharpPhp\Syntax\DoStmt;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\ForeachStmt;
use SharpPhp\Syntax\ForStmt;
use SharpPhp\Syntax\IfStmt;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\Stmt;
use SharpPhp\Syntax\SwitchStmt;
use SharpPhp\Syntax\ThrowStmt;
use SharpPhp\Syntax\TryStmt;
use SharpPhp\Syntax\WhileStmt;
use SharpPhp\Syntax\YieldStmt;

/**
 * Reachability of statement end points (C# spec, section "End points and reachability"),
 * used for "not all code paths return a value".
 *
 * Only the literal true counts as a constant condition. When in doubt the end point is
 * treated as unreachable, so valid code is never rejected.
 *
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait AnalyzesFlow
{
    /** @param list<Stmt> $statements */
    private function statementsEndReachable(array $statements): bool
    {
        foreach ($statements as $statement) {
            if (!$this->endReachable($statement)) {
                return false;
            }
        }

        return true;
    }

    private function endReachable(Stmt $s): bool
    {
        return match (true) {
            $s instanceof Block => $this->statementsEndReachable($s->statements),
            $s instanceof ReturnStmt, $s instanceof ThrowStmt, $s instanceof BreakStmt, $s instanceof ContinueStmt => false,
            $s instanceof YieldStmt => $s->value !== null, // "yield break" ends the iterator
            $s instanceof IfStmt => $this->endReachable($s->then)
                || ($s->else === null ? !$this->isConstantTrue($s->condition) : !$this->isConstantTrue($s->condition) && $this->endReachable($s->else)),
            $s instanceof WhileStmt => !$this->isConstantTrue($s->condition) || $this->jumpsOut($s->body, BreakStmt::class),
            $s instanceof ForStmt => ($s->condition !== null && !$this->isConstantTrue($s->condition)) || $this->jumpsOut($s->body, BreakStmt::class),
            $s instanceof DoStmt => (!$this->isConstantTrue($s->condition) && ($this->endReachable($s->body) || $this->jumpsOut($s->body, ContinueStmt::class)))
                || $this->jumpsOut($s->body, BreakStmt::class),
            $s instanceof ForeachStmt => true,
            $s instanceof SwitchStmt => $this->switchEndReachable($s),
            $s instanceof TryStmt => ($s->finally === null || $this->endReachable($s->finally))
                && ($this->endReachable($s->body) || array_filter($s->catches, fn ($c): bool => $this->endReachable($c->body)) !== []),
            default => true,
        };
    }

    private function switchEndReachable(SwitchStmt $s): bool
    {
        $hasDefault = false;
        foreach ($s->sections as $section) {
            foreach ($section->labels as $label) {
                $catchAll = $label->pattern === null || (in_array($label->pattern->kind, ['discard', 'var'], true) && $label->when === null);
                $hasDefault = $hasDefault || $catchAll;
            }
            if ($this->statementsEndReachable($section->statements) || $this->jumpsOut(new Block($section->statements), BreakStmt::class)) {
                return true;
            }
        }

        return !$hasDefault;
    }

    /**
     * Whether $body contains a break/continue that leaves the enclosing loop or switch
     * (nested loops and switches have their own targets).
     *
     * @param class-string<BreakStmt|ContinueStmt> $jump
     */
    private function jumpsOut(Stmt $body, string $jump): bool
    {
        return match (true) {
            $body instanceof $jump => true,
            $body instanceof Block => array_filter($body->statements, fn (Stmt $s): bool => $this->jumpsOut($s, $jump)) !== [],
            $body instanceof IfStmt => $this->jumpsOut($body->then, $jump) || ($body->else !== null && $this->jumpsOut($body->else, $jump)),
            $body instanceof TryStmt => $this->jumpsOut($body->body, $jump)
                || array_filter($body->catches, fn ($c): bool => $this->jumpsOut($c->body, $jump)) !== []
                || ($body->finally !== null && $this->jumpsOut($body->finally, $jump)),
            // A continue inside a switch still targets the enclosing loop.
            $body instanceof SwitchStmt => $jump === ContinueStmt::class
                && array_filter($body->sections, fn ($sec): bool => $this->jumpsOut(new Block($sec->statements), $jump)) !== [],
            default => false,
        };
    }

    private function isConstantTrue(Expr $e): bool
    {
        return $e instanceof LiteralExpr && $e->kind === 'bool' && $e->value === true;
    }
}
