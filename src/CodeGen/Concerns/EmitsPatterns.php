<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\CodeGen\Prec;
use SharpPhp\Compiler\CompileError;
use SharpPhp\Semantics\Type;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\IsPatternExpr;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\Pattern;
use SharpPhp\Syntax\PredefinedTypeExpr;
use SharpPhp\Syntax\SwitchExpr;

/**
 * Pattern matching: "is" expressions, switch expressions and switch statement labels.
 *
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait EmitsPatterns
{
    private function isPatternExpr(IsPatternExpr $e): Emitted
    {
        return $this->patternCondition($e->pattern, $this->expr($e->expr), $e);
    }

    /**
     * Boolean PHP expression that is true when $subject matches $pattern.
     * Pattern variables are declared in the current scope.
     */
    private function patternCondition(Pattern $pattern, Emitted $subject, Node $at): Emitted
    {
        $uses = $this->patternSubjectUses($pattern);
        $count = 0;
        $temp = null;

        $provider = function () use ($subject, $uses, &$count, &$temp, $at): Emitted {
            $count++;
            if ($uses <= 1 || $subject->isSimple()) {
                return $subject;
            }
            if ($count === 1) {
                $temp = $this->temp($at);

                return Emitted::value("($temp = {$subject->code})", $subject->type);
            }

            return Emitted::value((string) $temp, $subject->type);
        };

        return $this->patternTest($pattern, $provider);
    }

    private function patternSubjectUses(Pattern $pattern): int
    {
        return match ($pattern->kind) {
            'discard' => 0,
            'not' => $this->patternSubjectUses($pattern->operands[0]),
            'and', 'or' => $this->patternSubjectUses($pattern->operands[0]) + $this->patternSubjectUses($pattern->operands[1]),
            'property' => 1 + count($pattern->operands),
            default => 1,
        };
    }

    /**
     * @param callable(): Emitted $subject
     */
    private function patternTest(Pattern $p, callable $subject): Emitted
    {
        switch ($p->kind) {
            case 'discard':
                return Emitted::value('true', Type::bool());

            case 'var':
                $s = $subject();
                $this->scope->declare((string) $p->designation, $s->type);

                return Emitted::value("((\${$p->designation} = {$s->code}) || true)", Type::bool());

            case 'not':
                $inner = $p->operands[0];
                if ($inner->kind === 'constant' && $inner->expr instanceof LiteralExpr && $inner->expr->kind === 'null') {
                    return $this->infix('!==', $subject(), Emitted::value('null', Type::null()), Prec::EQ, Type::bool());
                }
                $test = $this->patternTest($inner, $subject);

                return Emitted::value('!' . $this->wrap($test, Prec::NOT), Type::bool(), Prec::NOT);

            case 'and':
            case 'or':
                $left = $this->patternTest($p->operands[0], $subject);
                $right = $this->patternTest($p->operands[1], $subject);

                return $p->kind === 'and'
                    ? $this->infix('&&', $left, $right, Prec::AND, Type::bool())
                    : $this->infix('||', $left, $right, Prec::OR, Type::bool());

            case 'relational':
                $s = $subject();

                return $this->infix((string) $p->operator, $s, $this->expr($p->expr, $s->type), Prec::REL, Type::bool());

            case 'type':
                return $this->typeTest($this->resolveType($p->type), $subject(), $p->designation);

            case 'property':
                return $this->propertyPatternTest($p, $subject);

            case 'constant':
                if ($this->patternExprIsType($p->expr)) {
                    return $this->typeTest($this->target($p->expr)->type, $subject(), null);
                }
                $s = $subject();
                $value = $this->expr($p->expr, $s->type);
                if ($value->type->isNull()) {
                    return $this->infix('===', $s, $value, Prec::EQ, Type::bool());
                }

                return $this->equality(true, $s, $value);
        }

        throw $this->error('SP1010', "Unsupported pattern '{$p->kind}'", $p);
    }

    /**
     * "Laptop { RamGb: >= 16, Name: var n } l": a type (or non-null) test, then each member
     * matched against its subpattern.
     *
     * @param callable(): Emitted $subject
     */
    private function propertyPatternTest(Pattern $p, callable $subject): Emitted
    {
        $first = $subject();
        if ($p->type !== null) {
            $type = $this->resolveType($p->type);
            $test = $this->typeTest($type, $first, $p->designation);
        } else {
            $type = $first->type->withNullable(false);
            if ($p->designation !== null && $p->designation !== '_') {
                $this->scope->declare($p->designation, $type);
                $test = Emitted::value("(\${$p->designation} = {$first->code}) !== null", Type::bool(), Prec::EQ);
            } else {
                $test = $this->infix('!==', $first, Emitted::value('null', Type::null()), Prec::EQ, Type::bool());
            }
        }

        foreach ($p->operands as $i => $subpattern) {
            $receiver = Emitted::value($subject()->code, $type);
            $access = (new MemberAccessExpr(new NameExpr('_', []), $p->members[$i], false, []))->at($p->memberNodes[$i] ?? $subpattern);
            $member = $this->valueOf($this->instanceMember($receiver, $access), $access);
            $test = $this->infix('&&', $test, $this->patternCondition($subpattern, $member, $subpattern), Prec::AND, Type::bool());
        }

        return $test;
    }

    private function typeTest(Type $type, Emitted $subject, ?string $designation): Emitted
    {
        $operand = $subject->code;
        $wrapped = $this->wrap($subject, Prec::ATOM);
        if ($designation !== null && $designation !== '_') {
            $this->scope->declare($designation, $type->withNullable(false));
            $operand = "\$$designation = {$subject->code}";
            $wrapped = "(\$$designation = {$subject->code})";
        }

        return match ($type->kind) {
            'int', 'float', 'string', 'bool', 'array', 'iterable' => Emitted::value("\\is_{$type->kind}($operand)", Type::bool()),
            'char' => Emitted::value($this->runtime('TypeCheck') . "::matches($operand, 'char')", Type::bool()),
            'closure' => Emitted::value("$wrapped instanceof \\Closure", Type::bool(), Prec::INSTANCEOF),
            'class' => Emitted::value("$wrapped instanceof " . $this->classRef((string) $type->class), Type::bool(), Prec::INSTANCEOF),
            'typeparam' => $type->class !== null
                ? Emitted::value("$wrapped instanceof " . $this->classRef($type->class), Type::bool(), Prec::INSTANCEOF)
                : Emitted::value("$wrapped !== null", Type::bool(), Prec::EQ),
            default => Emitted::value("$wrapped !== null", Type::bool(), Prec::EQ),
        };
    }

    /** Whether a constant pattern like "Circle" actually names a type. */
    private function patternExprIsType(?Expr $expr): bool
    {
        if (!($expr instanceof NameExpr || $expr instanceof MemberAccessExpr || $expr instanceof PredefinedTypeExpr)) {
            return false;
        }
        if ($expr instanceof NameExpr && $this->scope->lookup($expr->name) !== null) {
            return false;
        }
        try {
            return $this->target($expr)->kind === 'type';
        } catch (CompileError) {
            return false;
        }
    }

    private function switchExpr(SwitchExpr $e, ?Type $expected): Emitted
    {
        $subject = $this->expr($e->subject);

        $simple = true;
        foreach ($e->arms as $arm) {
            if ($arm->when !== null || ($arm->pattern->kind !== 'discard' && !$this->isConstantPattern($arm->pattern))) {
                $simple = false;
            }
        }

        $iife = null;
        if (!$simple && !$subject->isSimple()) {
            $iife = $this->temp($e);
            $original = $subject;
            $subject = Emitted::value($iife, $subject->type);
        }

        $arms = [];
        $types = [];
        foreach ($e->arms as $arm) {
            if ($simple) {
                $key = $arm->pattern->kind === 'discard' ? 'default' : $this->expr($arm->pattern->expr, $subject->type)->code;
            } elseif ($arm->pattern->kind === 'discard' && $arm->when === null) {
                $key = 'default';
            } else {
                $condition = $this->patternCondition($arm->pattern, $subject, $arm);
                if ($arm->when !== null) {
                    $condition = $this->infix('&&', $condition, $this->expr($arm->when, Type::bool()), Prec::AND, Type::bool());
                }
                $key = $condition->code;
            }
            $result = $this->expr($arm->result, $expected);
            if (!str_starts_with($result->code, 'throw ')) {
                $types[] = $result->type;
            }
            $arms[] = "$key => {$result->code}";
        }

        $type = array_shift($types) ?? Type::mixed();
        foreach ($types as $other) {
            $type = $this->unify($type, $other);
        }

        $body = " {\n    " . implode(",\n    ", $arms) . ",\n}";
        if ($simple) {
            return Emitted::value("match ({$subject->code})" . $body, $type);
        }
        if ($iife !== null) {
            return Emitted::value("(fn ($iife) => match (true)$body)({$original->code})", $type);
        }

        return Emitted::value('match (true)' . $body, $type);
    }
}
