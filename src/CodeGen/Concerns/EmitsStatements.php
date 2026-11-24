<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\EmitStep;
use SharpPhp\CodeGen\Emitted;
use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\Type;
use SharpPhp\Syntax\AwaitExpr;
use SharpPhp\Syntax\BinaryExpr;
use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\BreakStmt;
use SharpPhp\Syntax\ContinueStmt;
use SharpPhp\Syntax\DoStmt;
use SharpPhp\Syntax\EmptyStmt;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\ExprStmt;
use SharpPhp\Syntax\ForeachStmt;
use SharpPhp\Syntax\ForStmt;
use SharpPhp\Syntax\IfStmt;
use SharpPhp\Syntax\InvocationExpr;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\LocalDecl;
use SharpPhp\Syntax\LockStmt;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\NodeFinder;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\Stmt;
use SharpPhp\Syntax\SwitchStmt;
use SharpPhp\Syntax\ThrowStmt;
use SharpPhp\Syntax\TryStmt;
use SharpPhp\Syntax\UnaryExpr;
use SharpPhp\Syntax\UsingStmt;
use SharpPhp\Syntax\WhileStmt;
use SharpPhp\Syntax\YieldStmt;

/**
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait EmitsStatements
{
    /** C# exception => PHP engine errors that should be caught by the same catch clause. */
    private const NATIVE_EXCEPTIONS = [
        'System\\DivideByZeroException' => ['DivisionByZeroError'],
        'System\\ArithmeticException' => ['ArithmeticError'],
        'System\\InvalidCastException' => ['TypeError'],
        'System\\ArgumentException' => ['ValueError'],
    ];

    /** @param list<Stmt> $statements */
    private function statements(array $statements): void
    {
        // A local's scope is its whole block, also before its declaration (CS0841, CS0136).
        foreach ($statements as $statement) {
            if ($statement instanceof LocalDecl) {
                foreach ($statement->declarators as $declarator) {
                    $this->scope->declareLater($declarator->name);
                }
            }
        }
        foreach ($statements as $statement) {
            $this->tolerating(fn () => $this->statement($statement));
        }
    }

    /**
     * Emits one statement by its kind, after setting the source line for the line map; an EmitStep runs its callback
     * (the rest of a using statement). SP1010 for a statement that is not supported.
     */
    private function statement(Stmt $s): void
    {
        $this->w->sourceLine = $s->line;

        match (true) {
            $s instanceof Block => $this->blockStatement($s),
            $s instanceof LocalDecl => $this->localDeclaration($s),
            $s instanceof ExprStmt => $this->w->line($this->effect($s->expr) . ';'),
            $s instanceof IfStmt => $this->ifStatement($s),
            $s instanceof WhileStmt => $this->whileStatement($s),
            $s instanceof DoStmt => $this->doStatement($s),
            $s instanceof ForStmt => $this->forStatement($s),
            $s instanceof ForeachStmt => $this->foreachStatement($s),
            $s instanceof SwitchStmt => $this->switchStatement($s),
            $s instanceof ReturnStmt => $this->returnStatement($s),
            $s instanceof BreakStmt => $this->breakStatement($s),
            $s instanceof ContinueStmt => $this->continueStatement($s),
            $s instanceof ThrowStmt => $this->throwStatement($s),
            $s instanceof TryStmt => $this->tryStatement($s),
            $s instanceof YieldStmt => $this->yieldStatement($s),
            $s instanceof UsingStmt => $this->usingStatement($s),
            $s instanceof LockStmt => $this->lockStatement($s),
            $s instanceof EmitStep => ($s->emit)(),
            $s instanceof EmptyStmt => null,
            default => throw $this->error('SP1010', 'Unsupported statement', $s),
        };
    }

    /** Emits the body of a compound statement (inside braces already written by the caller). */
    private function body(Stmt $s): void
    {
        $this->w->indent();
        $this->inScope(function () use ($s): void {
            if ($s instanceof Block) {
                $this->statements($s->statements);
            } else {
                if ($s instanceof LocalDecl) {
                    throw $this->error('SP1023', 'Embedded statement cannot be a declaration', $s);
                }
                $this->statement($s);
            }
        });
        $this->w->outdent();
    }

    private function blockStatement(Block $block): void
    {
        $this->w->line('{');
        $this->body($block);
        $this->w->line('}');
    }

    /** @param 'using'|null $kind a using statement's resource (CS1656 when it is assigned) */
    private function localDeclaration(LocalDecl $s, ?string $kind = null): void
    {
        if ($s->isConst && $s->type?->name === 'var' && $s->type->args === [] && $this->resolver->findClass('var', $this->ctx, $s) === null) {
            throw $this->error('SP0822', 'Implicitly-typed variables cannot be constant', $s);
        }
        $declared = $s->type !== null ? $this->resolveType($s->type) : null;
        $kind = $s->isConst ? 'const' : $kind;
        if ($declared !== null && $declared->kind === 'class' && $this->symbolForType($declared)?->isStatic === true) {
            throw $this->error('SP0723', "Cannot declare a variable of static type '{$declared->describe()}'", $s->type ?? $s);
        }

        foreach ($s->declarators as $declarator) {
            $this->w->sourceLine = $declarator->line;
            if ($s->isConst && $declarator->init === null) {
                throw $this->error('SP0145', 'A const field requires a value to be provided', $declarator);
            }
            if ($s->isConst && !$this->isCSharpConstant($declarator->init)) {
                throw $this->error('SP0133', "The expression being assigned to '{$declarator->name}' must be constant", $declarator->init);
            }
            if ($declarator->init === null) {
                if ($declared === null) {
                    throw $this->error('SP0818', 'Implicitly-typed variables must be initialized', $declarator);
                }
                $this->declareLocal($declarator->name, $declared, $declarator, assigned: false);
                continue;
            }

            try {
                $value = $declared !== null ? $this->convert($declarator->init, $declared) : $this->expr($declarator->init);
            } catch (\SharpPhp\Compiler\CompileError $e) {
                // Analysis mode goes on after the error: later uses of the variable still resolve.
                if ($this->model !== null && !$this->scope->declaresLocally($declarator->name)) {
                    $this->declareLocal($declarator->name, $declared ?? Type::mixed(), $declarator);
                }
                throw $e;
            }
            if ($declared === null && $value->type->isNull()) {
                throw $this->error('SP0815', "Cannot assign <null> to an implicitly-typed variable", $declarator);
            }
            if ($declared === null && $value->type->kind === 'void') {
                throw $this->error('SP0815', 'Cannot assign void to an implicitly-typed variable', $declarator);
            }
            $this->declareLocal($declarator->name, $declared ?? $value->type, $declarator, kind: $kind);
            $root = $this->fn?->root();
            if ($root !== null && isset($root->reassigned[$declarator->name], $root->capturedByLambdas[$declarator->name])
                && in_array('loop', $this->fn->breakables, true)) {
                // A lambda captures this variable by reference: each iteration needs a new one, as in C#.
                $this->w->line('unset($' . $declarator->name . ');');
            }
            $this->w->line('$' . $declarator->name . ' = ' . $value->code . ';');
        }
    }

    private function condition(\SharpPhp\Syntax\Expr $expr): string
    {
        return $this->convert($expr, Type::bool())->code;
    }

    /** switch (flag) { case true: ... case false: ... } handles every bool. */
    private function coversTrueAndFalse(SwitchStmt $s): bool
    {
        $values = [];
        foreach ($s->sections as $section) {
            foreach ($section->labels as $label) {
                $expr = $label->isConstantCase() && $label->when === null ? $label->pattern?->expr : null;
                if ($expr instanceof LiteralExpr && $expr->kind === 'bool') {
                    $values[$expr->value ? 'true' : 'false'] = true;
                }
            }
        }

        return count($values) === 2;
    }

    /**
     * Whether a condition is a C# constant expression equal to true: the literal, a const field
     * (while (Forever)) or !, && and || over those. Such loops only end through break.
     */
    private function isConstantTrue(Expr $condition): bool
    {
        return $this->constantCondition($condition, 0) === true;
    }

    /**
     * The value of a condition that is a C# constant, or null: a literal (folded), !, && and || over constants, a
     * const field (Verbose, Config.Verbose) followed to its value; past a $depth of 16 (const fields that refer to
     * each other) it gives up.
     */
    private function constantCondition(Expr $e, int $depth): ?bool
    {
        if ($depth > 16) {
            return null; // const fields referring to each other in a cycle
        }
        $value = $this->evaluateConstant($e);
        if (is_bool($value)) {
            return $value;
        }
        if ($e instanceof UnaryExpr && $e->operator === '!' && !$e->postfix) {
            $operand = $this->constantCondition($e->operand, $depth + 1);

            return $operand === null ? null : !$operand;
        }
        if ($e instanceof BinaryExpr && ($e->operator === '&&' || $e->operator === '||')) {
            $left = $this->constantCondition($e->left, $depth + 1);
            $right = $this->constantCondition($e->right, $depth + 1);
            if ($left === null || $right === null) {
                return null;
            }

            return $e->operator === '&&' ? $left && $right : $left || $right;
        }
        $constant = $this->constantMember($e)?->constantValue;

        return $constant !== null ? $this->constantCondition($constant, $depth + 1) : null;
    }

    /** The const field that Verbose or Config.Verbose refers to, if it is one. */
    private function constantMember(Expr $e): ?MemberSymbol
    {
        $member = match (true) {
            $e instanceof NameExpr && $this->scope->lookup($e->name) === null && $this->class !== null
                => $this->symbols->findMember($this->class, $e->name),
            $e instanceof MemberAccessExpr && $e->target instanceof NameExpr && $this->scope->lookup($e->target->name) === null
                => ($type = $this->resolver->findClass($e->target->name, $this->ctx)) !== null ? $this->symbols->findMember($type, $e->name) : null,
            default => null,
        };

        return $member?->kind === 'const' ? $member : null;
    }

    /**
     * if / else if / else as one PHP if ... elseif ... else chain. Each branch starts from the flow state in which the
     * conditions before it were false, and the states at the ends of the branches are merged (with the state of no
     * branch run when there is no else); after a constant true condition, the later clauses are unreachable.
     */
    private function ifStatement(IfStmt $s): void
    {
        $outcomes = [];
        $clause = $s;
        $keyword = 'if';
        while (true) {
            $this->w->sourceLine = $clause->line;
            $this->w->line(($keyword === 'if' ? 'if (' : '} elseif (') . $this->condition($clause->condition) . ') {');
            $whenFalse = $this->flow->save();
            if ($this->isConstantTrue($clause->condition)) {
                $whenFalse = null; // the remaining clauses never run
            }
            $this->body($clause->then);
            $outcomes[] = $this->flow->save();
            $this->flow->restore($whenFalse);
            if (!$clause->else instanceof IfStmt) {
                break;
            }
            $clause = $clause->else;
            $keyword = 'elseif';
        }
        if ($clause->else !== null) {
            $this->w->line('} else {');
            $this->body($clause->else);
        }
        $outcomes[] = $this->flow->save();
        $this->flow->merge($outcomes);
        $this->w->line('}');
    }

    /**
     * Emits a loop body as a jump target for break/continue.
     *
     * @return array{breaks: list<array<string, true>>, continues: list<array<string, true>>} flow states recorded by its jumps
     */
    private function loopBody(Stmt $body): array
    {
        $this->currentFunction()->breakables[] = 'loop';
        $this->flow->enterJumpTarget('loop');
        try {
            $this->body($body);
        } finally {
            array_pop($this->currentFunction()->breakables);
        }

        return $this->flow->exitJumpTarget();
    }

    private function whileStatement(WhileStmt $s): void
    {
        $this->w->line('while (' . $this->condition($s->condition) . ') {');
        $whenFalse = $this->isConstantTrue($s->condition) ? null : $this->flow->save();
        $jumps = $this->loopBody($s->body);
        $this->flow->merge([$whenFalse, ...$jumps['breaks']]);
        $this->w->line('}');
    }

    private function doStatement(DoStmt $s): void
    {
        $this->w->line('do {');
        $jumps = $this->loopBody($s->body);
        $this->flow->merge([$this->flow->save(), ...$jumps['continues']]); // the condition is reached from both
        $this->w->sourceLine = $s->condition->line;
        $this->w->line('} while (' . $this->condition($s->condition) . ');');
        $whenFalse = $this->isConstantTrue($s->condition) ? null : $this->flow->save();
        $this->flow->merge([$whenFalse, ...$jumps['breaks']]);
    }

    /**
     * for (init; condition; iterators) as PHP's for, in a scope of its own; declared variables must be initialized
     * (SP0818). The body goes to a writer of its own first, so that the iterators, which run after it, are analysed
     * after it (definite assignment); without a condition or with a constant true one, only break leaves the loop.
     */
    private function forStatement(ForStmt $s): void
    {
        $this->inScope(function () use ($s): void {
            $init = [];
            if ($s->declaration !== null) {
                $declared = $s->declaration->type !== null ? $this->resolveType($s->declaration->type) : null;
                foreach ($s->declaration->declarators as $declarator) {
                    if ($declarator->init === null) {
                        throw $this->error('SP0818', 'Variables declared in a for statement must be initialized', $declarator);
                    }
                    $value = $declared !== null ? $this->convert($declarator->init, $declared) : $this->expr($declarator->init);
                    $this->declareLocal($declarator->name, $declared ?? $value->type, $declarator);
                    $init[] = '$' . $declarator->name . ' = ' . $value->code;
                }
            }
            foreach ($s->initializers as $expr) {
                $init[] = $this->effect($expr);
            }
            $condition = $s->condition !== null ? $this->condition($s->condition) : '';
            $whenFalse = $s->condition === null || $this->isConstantTrue($s->condition) ? null : $this->flow->save();

            // The iterators run after the body, so they are analysed after it: the body goes to its own writer.
            $outer = $this->w;
            $this->w = new \SharpPhp\CodeGen\CodeWriter();
            $this->w->sourceLine = $outer->sourceLine;
            try {
                $jumps = $this->loopBody($s->body);
                $this->flow->merge([$this->flow->save(), ...$jumps['continues']]);
                $iterators = array_map(fn (Expr $e): string => $this->effect($e), $s->iterators);
                $body = $this->w;
            } finally {
                $this->w = $outer;
            }
            $this->flow->merge([$whenFalse, ...$jumps['breaks']]);

            $this->w->line('for (' . implode(', ', $init) . '; ' . $condition . '; ' . implode(', ', $iterators) . ') {');
            $this->w->append($body);
            $this->w->line('}');
        });
    }

    /**
     * foreach as PHP's foreach over the collection's value, a string by its chars (`\mb_str_split()`). The variable
     * has the declared type or the element type and cannot be assigned (SP1656); `var (k, v)` deconstructs into
     * `[$k, $v]` with the element type's arguments. SP1579 when the type cannot be enumerated.
     */
    private function foreachStatement(ForeachStmt $s): void
    {
        $collection = $this->expr($s->collection);
        $this->requireEnumerable($collection, $s->collection);
        $elementType = $this->elementTypeOf($collection->type);
        $source = $collection->type->isString()
            ? '\\mb_str_split(' . $collection->code . ')'
            : $this->wrap($collection, \SharpPhp\CodeGen\Prec::ASSIGN);

        $this->inScope(function () use ($s, $source, $elementType): void {
            if ($s->deconstruction !== []) {
                $types = $elementType->isClass() && $elementType->args !== [] ? $elementType->args : [];
                foreach ($s->deconstruction as $i => $name) {
                    $this->declareLocal($name, $types[$i] ?? Type::mixed(), $s, kind: 'foreach');
                }
                $target = '[' . implode(', ', array_map(static fn (string $n): string => '$' . $n, $s->deconstruction)) . ']';
            } else {
                $type = $s->type !== null ? $this->resolveType($s->type) : $elementType;
                $this->declareLocal((string) $s->variable, $type, $s, kind: 'foreach');
                $target = '$' . $s->variable;
            }

            $this->w->line("foreach ($source as $target) {");
            $empty = $this->flow->save(); // the collection may be empty
            $jumps = $this->loopBody($s->body);
            $this->flow->merge([$empty, ...$jumps['breaks']]);
            $this->w->line('}');
        });
    }

    /**
     * C#'s CS0160: a catch clause after one without a filter that catches its type or a base type.
     * The general catch clause (catch { }) is not checked (C# only warns there).
     *
     * @param list<Type> $previous the types of the clauses before, without a filter
     */
    private function requireReachableCatch(\SharpPhp\Syntax\CatchClause $catch, Type $type, array &$previous): void
    {
        if ($catch->type === null) {
            return;
        }
        foreach ($previous as $earlier) {
            if ($this->symbols->isSubtypeOf((string) $type->class, (string) $earlier->class)) {
                throw $this->error('SP0160', "A previous catch clause already catches all exceptions of this or of a super type ('{$earlier->describe()}')", $catch->type);
            }
        }
        if ($catch->when === null) {
            $previous[] = $type;
        }
    }

    /** C#'s CS0152: two labels of a switch statement with the same constant value, or two defaults. */
    private function requireDistinctLabels(SwitchStmt $s): void
    {
        $seen = [];
        foreach ($s->sections as $section) {
            foreach ($section->labels as $label) {
                if ($label->pattern === null) {
                    [$key, $text] = ['default', 'default'];
                } elseif ($label->isConstantCase() && $label->when === null && $label->pattern->expr !== null) {
                    $expr = $label->pattern->expr;
                    $value = $this->evaluateConstant($expr);
                    if ($value !== self::notConstant()) {
                        $key = match (true) {
                            is_int($value), is_float($value) => 'number:' . (float) $value,
                            is_bool($value) => 'bool:' . var_export($value, true),
                            $value === null => 'null',
                            default => 'text:' . \SharpPhp\Runtime\Op::str($value),
                        };
                        $text = is_string($value) ? $value : \SharpPhp\Runtime\Op::str($value);
                    } elseif ($expr instanceof \SharpPhp\Syntax\MemberAccessExpr && $expr->target instanceof \SharpPhp\Syntax\NameExpr) {
                        // An enum member (or another constant of a type): E.A
                        $key = 'member:' . $expr->target->name . '.' . $expr->name;
                        $text = $expr->target->name . '.' . $expr->name;
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
                if (isset($seen[$key])) {
                    throw $this->error('SP0152', "The switch statement contains multiple cases with the label value '$text'", $label);
                }
                $seen[$key] = true;
            }
        }
    }

    /**
     * C#'s CS1579: foreach needs a GetEnumerator(): not a number, bool, char, enum or struct, nor a class of
     * the program or the library without one. Values of PHP classes and of unknown types are not checked.
     */
    private function requireEnumerable(Emitted $collection, \SharpPhp\Syntax\Node $at): void
    {
        $kind = $this->operandKind($collection->type);
        if ($kind === null || $kind === 'string' || $kind === 'null' || $collection->type->kind === 'array') {
            return;
        }
        if ($kind === 'class' || $kind === 'struct') {
            $symbol = $this->symbolForType($collection->type);
            if ($symbol === null || ($symbol->external && !self::isRuntimeLibrary($symbol)) || $symbol->elementType !== null
                || $this->symbols->findMember($symbol, 'GetEnumerator') !== null || $this->findMethod($symbol, 'GetEnumerator') !== null
                || $this->symbols->isSubtypeOf($symbol->fqn, \Traversable::class)) {
                return;
            }
        }
        $type = $collection->type->describe();
        throw $this->error('SP1579', "foreach statement cannot operate on variables of type '$type' because '$type' does not contain a public instance or extension definition for 'GetEnumerator'", $at);
    }

    private function elementTypeOf(Type $type): Type
    {
        if (in_array($type->kind, ['array', 'iterable', 'string'], true)) {
            return $type->element();
        }
        $symbol = $this->symbolForType($type);
        if ($symbol !== null && $symbol->elementType !== null) {
            return $symbol->elementType->substitute($symbol->substitutionFor($type));
        }

        return Type::mixed();
    }

    /**
     * A switch statement as PHP's switch: on an int or enum with only constant labels `switch ($x) { case 1: ... }`,
     * otherwise `switch (true)` with each label's pattern condition and when clause (a subject that is not simple is
     * stored in a temporary first). Each section has a scope of its own and must not fall through (SP0163); equal
     * labels are SP0152. Without a default label, a non-constant pattern or both true and false, no section may run.
     */
    private function switchStatement(SwitchStmt $s): void
    {
        $subject = $this->expr($s->subject);
        $this->requireDistinctLabels($s);
        $subjectSymbol = $this->symbolForType($subject->type);
        $strictSafe = $subject->type->isInt() || ($subjectSymbol !== null && $subjectSymbol->isEnum());

        $simple = $strictSafe;
        foreach ($s->sections as $section) {
            foreach ($section->labels as $label) {
                if ($label->pattern !== null && ($label->when !== null || !$this->isConstantPattern($label->pattern))) {
                    $simple = false;
                }
            }
        }

        if ($simple) {
            $this->w->line('switch (' . $subject->code . ') {');
        } else {
            if (!$subject->isSimple()) {
                $temp = $this->currentFunction()->temp('switch');
                $this->w->line("$temp = {$subject->code};");
                $subject = Emitted::value($temp, $subject->type);
            }
            $this->w->line('switch (true) {');
        }

        $before = $this->flow->save();
        $catchAll = false;
        $this->currentFunction()->breakables[] = 'switch';
        $this->flow->enterJumpTarget('switch');
        try {
            $this->w->indent();
            foreach ($s->sections as $section) {
                $this->w->sourceLine = $section->line;
                $this->flow->restore($before);
                foreach ($section->labels as $label) {
                    $catchAll = $catchAll || !$label->isConstantCase();
                }
                $this->inScope(function () use ($section, $simple, $subject): void {
                    foreach ($section->labels as $label) {
                        $this->w->sourceLine = $label->line;
                        if ($label->pattern === null) {
                            $this->w->line('default:');
                        } elseif ($simple) {
                            $this->w->line('case ' . $this->expr($label->pattern->expression())->code . ':');
                        } else {
                            $condition = $this->patternCondition($label->pattern, $subject, $label->pattern);
                            if ($label->when !== null) {
                                $condition = $this->wrap($condition, \SharpPhp\CodeGen\Prec::AND + 1) . ' && ' . $this->wrap($this->expr($label->when, Type::bool()), \SharpPhp\CodeGen\Prec::AND + 1);
                            } else {
                                $condition = $condition->code;
                            }
                            $this->w->line("case $condition:");
                        }
                    }
                    $this->w->indent();
                    $this->statements($section->statements);
                    $this->w->outdent();
                });
                if ($this->flow->isReachable()) {
                    // PHP would silently fall through into the next case.
                    throw $this->error('SP0163', 'Control cannot fall through from one case label to another; end the section with break, return or throw', $section->labels[array_key_last($section->labels)] ?? $section);
                }
            }
            $this->w->outdent();
        } finally {
            array_pop($this->currentFunction()->breakables);
        }
        $jumps = $this->flow->exitJumpTarget();
        $catchAll = $catchAll || ($subject->type->isBool() && $this->coversTrueAndFalse($s));
        // Without a catch-all label no section may run. Non-constant patterns (case < 0: ... case >= 0:)
        // can be exhaustive; that is not analysed, so they count as covering every value.
        $this->flow->merge([$catchAll ? null : $before, ...$jumps['breaks']]);
        $this->w->line('}');
    }

    private function yieldStatement(YieldStmt $s): void
    {
        if ($s->value === null) {
            $this->w->line('return;'); // yield break
            $this->flow->markUnreachable();

            return;
        }
        $this->w->line('yield ' . $this->expr($s->value)->code . ';');
    }

    /**
     * lock (gate) { ... }: one thread runs SharpPHP code, so only C#'s rules remain: the gate is an
     * object that is not null (ArgumentNullException), and no await inside (CS1996), where another
     * async method could run before the lock is released.
     */
    private function lockStatement(LockStmt $s): void
    {
        $gate = $this->expr($s->target);
        if ($gate->type->isNumeric() || $gate->type->isBool() || $gate->type->isChar() || ($gate->type->isClass() && $this->isValueType($gate->type))) {
            throw $this->error('SP0185', "'{$gate->type->describe()}' is not a reference type as required by the lock statement", $s->target);
        }
        if (NodeFinder::contains($s->body, static fn (Node $n): bool => $n instanceof AwaitExpr)) {
            throw $this->error('SP1996', 'Cannot await in the body of a lock statement', $s);
        }
        $this->w->line($this->runtime('Op') . '::lockTarget(' . $gate->code . ');');
        $this->w->line('{');
        $this->body($s->body);
        $this->w->line('}');
    }

    private function returnStatement(ReturnStmt $s): void
    {
        if ($s->value === null) {
            $this->w->line('return;');
        } elseif ($this->currentFunction()->returnType->kind === 'void') {
            throw $this->currentFunction()->isAsync
                ? $this->error('SP1997', "Since this is an async method that returns 'Task', a return keyword must not be followed by an object expression. Did you intend to return 'Task<T>'?", $s)
                : $this->error('SP0127', 'Since this method returns void, a return keyword must not be followed by an object expression', $s);
        } else {
            $this->w->line('return ' . $this->returnedToPhp($this->convert($s->value, $this->currentFunction()->returnType)->code) . ';');
        }
        $this->requireOutParamsAssigned($s);
        $this->flow->markUnreachable();
    }

    private function breakStatement(BreakStmt $s): void
    {
        if ($this->currentFunction()->breakables === []) {
            throw $this->error('SP0139', 'No enclosing loop out of which to break or continue', $s);
        }
        $this->w->line('break;');
        $this->flow->jumpBreak();
    }

    private function continueStatement(ContinueStmt $s): void
    {
        $levels = 1;
        for ($i = count($this->currentFunction()->breakables) - 1; $i >= 0; $i--) {
            if ($this->currentFunction()->breakables[$i] === 'loop') {
                $this->w->line($levels === 1 ? 'continue;' : "continue $levels;");
                $this->flow->jumpContinue();

                return;
            }
            $levels++;
        }

        throw $this->error('SP0139', 'No enclosing loop out of which to break or continue', $s);
    }

    private function throwStatement(ThrowStmt $s): void
    {
        if ($s->value !== null) {
            $this->w->line('throw ' . $this->thrown($this->expr($s->value), $s->value)->code . ';');
            $this->flow->markUnreachable();

            return;
        }
        $variable = $this->currentFunction()->catchVariables[array_key_last($this->currentFunction()->catchVariables) ?? -1] ?? null;
        if ($variable === null) {
            throw $this->error('SP0156', 'A throw statement with no arguments is not allowed outside of a catch clause', $s);
        }
        $this->w->line("throw $variable;");
        $this->flow->markUnreachable();
    }

    /**
     * using (var a = A(), b = B()) body  =>  a = A(); try { b = B(); try { body } finally { b?.Dispose(); } }
     * finally { a?.Dispose(); }: every resource is disposed, in reverse order, however the body is left.
     */
    private function usingStatement(UsingStmt $s): void
    {
        $body = $s->body ?? throw $this->error('SP1023', 'Embedded statement cannot be a declaration or labeled statement', $s);
        $resources = [];
        if ($s->declaration !== null) {
            foreach ($s->declaration->declarators as $declarator) {
                $resources[] = (new LocalDecl($s->declaration->type, [$declarator], false))->at($declarator);
            }
        } elseif ($s->expression !== null) {
            $resources[] = $s->expression;
        }
        $this->inScope(fn () => $this->usingResources($resources, $body));
    }

    /** @param list<LocalDecl|Expr> $resources */
    private function usingResources(array $resources, Stmt $body): void
    {
        if ($resources === []) {
            $body instanceof Block ? $this->statements($body->statements) : $this->statement($body);

            return;
        }
        $resource = array_shift($resources);
        if ($resource instanceof LocalDecl) {
            $this->localDeclaration($resource, 'using');
            $at = $resource->declarators[0];
            $name = $at->name;
            $type = $this->scope->lookup($name)[0] ?? Type::mixed();
        } else {
            $at = $resource;
            $value = $this->expr($resource);
            $type = $value->type;
            $name = substr($this->currentFunction()->temp('using'), 1);
            $this->declareLocal($name, $type, $resource);
            $this->w->line('$' . $name . ' = ' . $value->code . ';');
        }
        $disposable = Type::class(Names::RUNTIME_NAMESPACE . '\\System\\IDisposable');
        if (!$type->isNull() && !$this->conversions->isImplicit($type, $disposable)) {
            throw $this->error('SP1674', "'{$type->describe()}': type used in a using statement must be implicitly convertible to 'System.IDisposable'", $at);
        }

        $dispose = (new ExprStmt((new InvocationExpr(
            (new MemberAccessExpr((new NameExpr($name, []))->at($at), 'Dispose', true, []))->at($at),
            [],
        ))->at($at)))->at($at);
        $rest = (new EmitStep(fn () => $this->usingResources($resources, $body)))->at($body);
        $this->tryStatement((new TryStmt((new Block([$rest]))->at($body), [], (new Block([$dispose]))->at($at)))->at($at));
    }

    /**
     * try / catch / finally. A catch clause also catches the PHP errors its type stands for (DivideByZeroException:
     * DivisionByZeroError); catch (Exception e) catches every \Throwable and gives e the C# exception (ErrorHandler).
     * With a catch of a specific type, an inner try translates engine errors (a call on null) first. Clauses with a
     * when filter go to filteredCatches(). SP0155 (not an exception type), SP0160 (an earlier clause catches it).
     */
    private function tryStatement(TryStmt $s): void
    {
        $this->w->line('try {');
        $before = $this->flow->save();
        // A PHP engine error that stands for NullReferenceException ("... on null") must reach the
        // catch clause for that type; an inner try turns it into the C# exception first.
        $translate = $this->catchesSpecificExceptions($s);
        if ($translate) {
            $this->w->indent();
            $this->w->line('try {');
        }
        $this->body($s->body);
        if ($translate) {
            $error = $this->currentFunction()->temp('error');
            $this->w->line("} catch (\\Error $error) {");
            $this->w->line('    throw ' . $this->runtime('ErrorHandler') . "::translate($error);");
            $this->w->line('}');
            $this->w->outdent();
        }
        $outcomes = [$this->flow->save()];

        // With an exception filter (catch (E e) when (...)), a clause whose filter is false lets the
        // next clauses try, as in C#: one PHP catch, then the clauses in order.
        $filtered = array_filter($s->catches, static fn ($c): bool => $c->when !== null) !== [];
        if ($filtered) {
            $this->filteredCatches($s, $before, $outcomes);
        }
        $previous = [];
        foreach ($filtered ? [] : $s->catches as $catch) {
            $this->w->sourceLine = $catch->line;
            $type = $catch->type !== null
                ? $this->resolveType($catch->type)
                : Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Exception');
            if (!$type->isClass() || !$this->symbols->isThrowable((string) $type->class)) {
                throw $this->error('SP0155', "The type caught or thrown must be derived from System.Exception", $catch->type ?? $catch);
            }
            $this->requireReachableCatch($catch, $type, $previous);

            $this->flow->restore($before); // the exception may come before any assignment in the try block
            $variable = $catch->variable !== null ? '$' . $catch->variable : $this->currentFunction()->temp('e');
            $this->w->line('} catch (' . $this->catchTypes((string) $type->class) . " $variable) {");
            $this->w->indent();
            $this->inScope(function () use ($catch, $type, $variable): void {
                if ($catch->variable !== null) {
                    $this->declareLocal($catch->variable, $type, $catch);
                    if ($this->catchTypes((string) $type->class) === '\\Throwable') {
                        // catch (Exception e) also receives PHP engine errors: give e the C# type.
                        $this->w->line("$variable = " . $this->runtime('ErrorHandler') . "::translate($variable);");
                    }
                }
                $this->currentFunction()->catchVariables[] = $variable;
                try {
                    if ($catch->when !== null) {
                        $this->w->line('if (!' . $this->wrap($this->expr($catch->when, Type::bool()), \SharpPhp\CodeGen\Prec::NOT) . ') {');
                        $this->w->line("    throw $variable;");
                        $this->w->line('}');
                    }
                    $this->statements($catch->body->statements);
                } finally {
                    array_pop($this->currentFunction()->catchVariables);
                }
            });
            $this->w->outdent();
            $outcomes[] = $this->flow->save();
        }
        $this->flow->merge($outcomes);

        if ($s->finally !== null) {
            $afterTry = $this->flow->save();
            $this->flow->restore($before);
            $this->w->line('} finally {');
            $this->body($s->finally);
            $this->flow->applyFinally($before, $afterTry);
        }
        $this->w->line('}');
    }

    /**
     * The catch clauses of a try with an exception filter: one PHP catch of every Throwable, then each
     * clause in order, by its type and filter; none matching throws the exception on.
     *
     * @param array<string, true>|null $before the flow state before the try block
     * @param list<array<string, true>|null> $outcomes
     */
    private function filteredCatches(TryStmt $s, ?array $before, array &$outcomes): void
    {
        $caught = $this->currentFunction()->temp('caught');
        $this->w->line("} catch (\\Throwable $caught) {");
        $this->w->indent();
        $this->w->line("$caught = " . $this->runtime('ErrorHandler') . "::translate($caught);");
        $previous = [];
        foreach ($s->catches as $i => $catch) {
            $this->w->sourceLine = $catch->line;
            $type = $catch->type !== null
                ? $this->resolveType($catch->type)
                : Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Exception');
            if (!$type->isClass() || !$this->symbols->isThrowable((string) $type->class)) {
                throw $this->error('SP0155', "The type caught or thrown must be derived from System.Exception", $catch->type ?? $catch);
            }
            $this->requireReachableCatch($catch, $type, $previous);
            $this->flow->restore($before);
            $variable = $catch->variable !== null ? '$' . $catch->variable : $caught;
            $this->inScope(function () use ($catch, $type, $variable, $caught, $i): void {
                $classes = $this->catchTypes((string) $type->class);
                $test = $classes === '\\Throwable' ? 'true' : implode(' || ', array_map(static fn (string $c): string => "$caught instanceof " . trim($c), explode('|', $classes)));
                $condition = $classes === '\\Throwable' ? '' : "($test)";
                if ($catch->variable !== null) {
                    $this->declareLocal($catch->variable, $type, $catch);
                    $condition .= ($condition !== '' ? ' && ' : '') . "(($variable = $caught) || true)";
                }
                if ($catch->when !== null) {
                    $filter = $this->wrap($this->expr($catch->when, Type::bool()), \SharpPhp\CodeGen\Prec::AND);
                    $condition .= ($condition !== '' ? ' && ' : '') . $filter;
                }
                $this->w->line(($i === 0 ? 'if' : '} elseif') . ' (' . ($condition !== '' ? $condition : 'true') . ') {');
                $this->w->indent();
                $this->currentFunction()->catchVariables[] = $variable;
                try {
                    $this->statements($catch->body->statements);
                } finally {
                    array_pop($this->currentFunction()->catchVariables);
                }
                $this->w->outdent();
            });
            $outcomes[] = $this->flow->save();
        }
        $this->w->line('} else {');
        $this->w->line("    throw $caught;");
        $this->w->line('}');
        $this->w->outdent();
    }

    /** Whether a catch clause names an exception type other than System.Exception (which catches everything). */
    private function catchesSpecificExceptions(TryStmt $s): bool
    {
        foreach ($s->catches as $catch) {
            if ($catch->type !== null) {
                $type = $this->resolveType($catch->type);
                if ($type->isClass() && $this->catchTypes((string) $type->class) !== '\\Throwable') {
                    return true;
                }
            }
        }

        return false;
    }

    private function catchTypes(string $fqn): string
    {
        $runtime = Names::RUNTIME_NAMESPACE . '\\';
        if (strcasecmp($fqn, $runtime . 'System\\Exception') === 0) {
            return '\\Throwable';
        }
        $types = [$this->classRef($fqn)];
        $relative = str_starts_with($fqn, $runtime) ? substr($fqn, strlen($runtime)) : '';
        foreach (self::NATIVE_EXCEPTIONS[$relative] ?? [] as $native) {
            $types[] = '\\' . $native;
        }

        return implode(' | ', $types);
    }

    private function isConstantPattern(\SharpPhp\Syntax\Pattern $pattern): bool
    {
        if ($pattern->kind !== 'constant') {
            return false;
        }

        return $pattern->expr instanceof LiteralExpr || !$this->patternExprIsType($pattern->expr);
    }
}
