<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\CodeGen\EmitStep;
use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\Type;
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
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\LocalDecl;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\Stmt;
use SharpPhp\Syntax\SwitchStmt;
use SharpPhp\Syntax\ThrowStmt;
use SharpPhp\Syntax\TryStmt;
use SharpPhp\Syntax\InvocationExpr;
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
        foreach ($statements as $statement) {
            $this->tolerating(fn () => $this->statement($statement));
        }
    }

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

    private function localDeclaration(LocalDecl $s): void
    {
        $declared = $s->type !== null ? $this->resolveType($s->type) : null;

        foreach ($s->declarators as $declarator) {
            $this->w->sourceLine = $declarator->line;
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
            $this->declareLocal($declarator->name, $declared ?? $value->type, $declarator);
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

    private function foreachStatement(ForeachStmt $s): void
    {
        $collection = $this->expr($s->collection);
        $elementType = $this->elementTypeOf($collection->type);
        $source = $collection->type->isString()
            ? '\\mb_str_split(' . $collection->code . ')'
            : $this->wrap($collection, \SharpPhp\CodeGen\Prec::ASSIGN);

        $this->inScope(function () use ($s, $source, $elementType): void {
            if ($s->deconstruction !== []) {
                $types = $elementType->isClass() && $elementType->args !== [] ? $elementType->args : [];
                foreach ($s->deconstruction as $i => $name) {
                    $this->declareLocal($name, $types[$i] ?? Type::mixed(), $s);
                }
                $target = '[' . implode(', ', array_map(static fn (string $n): string => '$' . $n, $s->deconstruction)) . ']';
            } else {
                $type = $s->type !== null ? $this->resolveType($s->type) : $elementType;
                $this->declareLocal((string) $s->variable, $type, $s);
                $target = '$' . $s->variable;
            }

            $this->w->line("foreach ($source as $target) {");
            $empty = $this->flow->save(); // the collection may be empty
            $jumps = $this->loopBody($s->body);
            $this->flow->merge([$empty, ...$jumps['breaks']]);
            $this->w->line('}');
        });
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

    private function switchStatement(SwitchStmt $s): void
    {
        $subject = $this->expr($s->subject);
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
            $this->w->line('throw ' . $this->expr($s->value)->code . ';');
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
            $this->localDeclaration($resource);
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

        foreach ($s->catches as $catch) {
            $this->w->sourceLine = $catch->line;
            $type = $catch->type !== null
                ? $this->resolveType($catch->type)
                : Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Exception');
            if (!$type->isClass() || !$this->symbols->isThrowable((string) $type->class)) {
                throw $this->error('SP0155', "The type caught or thrown must be derived from System.Exception", $catch->type ?? $catch);
            }

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
