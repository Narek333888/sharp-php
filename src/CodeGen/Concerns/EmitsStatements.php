<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\Type;
use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\BreakStmt;
use SharpPhp\Syntax\ContinueStmt;
use SharpPhp\Syntax\DoStmt;
use SharpPhp\Syntax\EmptyStmt;
use SharpPhp\Syntax\ExprStmt;
use SharpPhp\Syntax\ForeachStmt;
use SharpPhp\Syntax\ForStmt;
use SharpPhp\Syntax\IfStmt;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\LocalDecl;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\Stmt;
use SharpPhp\Syntax\SwitchStmt;
use SharpPhp\Syntax\ThrowStmt;
use SharpPhp\Syntax\TryStmt;
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
            $this->statement($statement);
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
            $s instanceof YieldStmt => $this->w->line($s->value === null ? 'return;' : 'yield ' . $this->expr($s->value)->code . ';'),
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

            $value = $declared !== null ? $this->convert($declarator->init, $declared) : $this->expr($declarator->init);
            if ($declared === null && $value->type->isNull()) {
                throw $this->error('SP0815', "Cannot assign <null> to an implicitly-typed variable", $declarator);
            }
            if ($declared === null && $value->type->kind === 'void') {
                throw $this->error('SP0815', 'Cannot assign void to an implicitly-typed variable', $declarator);
            }
            $this->declareLocal($declarator->name, $declared ?? $value->type, $declarator);
            $this->w->line('$' . $declarator->name . ' = ' . $value->code . ';');
        }
    }

    private function condition(\SharpPhp\Syntax\Expr $expr): string
    {
        return $this->convert($expr, Type::bool())->code;
    }

    private function ifStatement(IfStmt $s): void
    {
        $this->w->line('if (' . $this->condition($s->condition) . ') {');
        $before = $this->assignments->save();
        $outcomes = [];
        $this->body($s->then);
        if ($this->flow->endReachable($s->then)) {
            $outcomes[] = $this->assignments->save();
        }
        $last = $s;
        $else = $s->else;
        while ($else instanceof IfStmt) {
            $this->assignments->restore($before);
            $this->w->sourceLine = $else->line;
            $this->w->line('} elseif (' . $this->condition($else->condition) . ') {');
            $before = $this->assignments->save();
            $this->body($else->then);
            if ($this->flow->endReachable($else->then)) {
                $outcomes[] = $this->assignments->save();
            }
            $last = $else;
            $else = $else->else;
        }
        $this->assignments->restore($before);
        if ($else !== null) {
            $this->w->line('} else {');
            $this->body($else);
            if ($this->flow->endReachable($else)) {
                $outcomes[] = $this->assignments->save();
            }
        } elseif (!$this->flow->isConstantTrue($last->condition)) {
            $outcomes[] = $before;
        }
        $this->assignments->merge($outcomes);
        $this->w->line('}');
    }

    private function loopBody(Stmt $body): void
    {
        $this->fn->breakables[] = 'loop';
        try {
            $this->body($body);
        } finally {
            array_pop($this->fn->breakables);
        }
    }

    private function whileStatement(WhileStmt $s): void
    {
        $this->w->line('while (' . $this->condition($s->condition) . ') {');
        $before = $this->assignments->save();
        $this->loopBody($s->body);
        if (!$this->flow->isConstantTrue($s->condition)) {
            $this->assignments->restore($before); // the body may not run at all
        }
        $this->w->line('}');
    }

    private function doStatement(DoStmt $s): void
    {
        $this->w->line('do {');
        $this->loopBody($s->body);
        $this->w->sourceLine = $s->condition->line;
        $this->w->line('} while (' . $this->condition($s->condition) . ');');
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
            $before = $this->assignments->save();
            $iterators = array_map(fn ($e): string => $this->effect($e), $s->iterators);

            $this->w->line('for (' . implode(', ', $init) . '; ' . $condition . '; ' . implode(', ', $iterators) . ') {');
            $this->loopBody($s->body);
            if ($s->condition !== null && !$this->flow->isConstantTrue($s->condition)) {
                $this->assignments->restore($before);
            }
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
            $before = $this->assignments->save();
            $this->loopBody($s->body);
            $this->assignments->restore($before);
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
                $temp = $this->fn->temp('switch');
                $this->w->line("$temp = {$subject->code};");
                $subject = Emitted::value($temp, $subject->type);
            }
            $this->w->line('switch (true) {');
        }

        $before = $this->assignments->save();
        $outcomes = [];
        $hasDefault = false;
        $this->fn->breakables[] = 'switch';
        try {
            $this->w->indent();
            foreach ($s->sections as $section) {
                $this->w->sourceLine = $section->line;
                $this->assignments->restore($before);
                foreach ($section->labels as $label) {
                    $hasDefault = $hasDefault || $this->flow->isCatchAll($label);
                }
                $this->inScope(function () use ($section, $simple, $subject): void {
                    foreach ($section->labels as $label) {
                        $this->w->sourceLine = $label->line;
                        if ($label->pattern === null) {
                            $this->w->line('default:');
                        } elseif ($simple) {
                            $this->w->line('case ' . $this->expr($label->pattern->expr)->code . ':');
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
                if ($this->flow->sectionExits($section)) {
                    $outcomes[] = $this->assignments->save();
                }
            }
            $this->w->outdent();
        } finally {
            array_pop($this->fn->breakables);
        }
        if (!$hasDefault) {
            $outcomes[] = $before;
        }
        $this->assignments->merge($outcomes);
        $this->w->line('}');
    }

    private function returnStatement(ReturnStmt $s): void
    {
        if ($s->value === null) {
            $this->w->line('return;');

            return;
        }
        if ($this->fn->returnType->kind === 'void') {
            throw $this->error('SP0127', 'Since this method returns void, a return keyword must not be followed by an object expression', $s);
        }
        $this->w->line('return ' . $this->convert($s->value, $this->fn->returnType)->code . ';');
    }

    private function breakStatement(BreakStmt $s): void
    {
        if ($this->fn->breakables === []) {
            throw $this->error('SP0139', 'No enclosing loop out of which to break or continue', $s);
        }
        $this->w->line('break;');
    }

    private function continueStatement(ContinueStmt $s): void
    {
        $levels = 1;
        for ($i = count($this->fn->breakables) - 1; $i >= 0; $i--) {
            if ($this->fn->breakables[$i] === 'loop') {
                $this->w->line($levels === 1 ? 'continue;' : "continue $levels;");

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

            return;
        }
        $variable = $this->fn->catchVariables[array_key_last($this->fn->catchVariables) ?? -1] ?? null;
        if ($variable === null) {
            throw $this->error('SP0156', 'A throw statement with no arguments is not allowed outside of a catch clause', $s);
        }
        $this->w->line("throw $variable;");
    }

    private function tryStatement(TryStmt $s): void
    {
        $this->w->line('try {');
        $before = $this->assignments->save();
        $outcomes = [];
        $this->body($s->body);
        if ($this->flow->endReachable($s->body)) {
            $outcomes[] = $this->assignments->save();
        }

        foreach ($s->catches as $catch) {
            $this->w->sourceLine = $catch->line;
            $type = $catch->type !== null
                ? $this->resolveType($catch->type)
                : Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Exception');
            if (!$type->isClass() || !$this->symbols->isThrowable((string) $type->class)) {
                throw $this->error('SP0155', "The type caught or thrown must be derived from System.Exception", $catch->type ?? $catch);
            }

            $this->assignments->restore($before); // the exception may come before any assignment in the try block
            $variable = $catch->variable !== null ? '$' . $catch->variable : $this->fn->temp('e');
            $this->w->line('} catch (' . $this->catchTypes((string) $type->class) . " $variable) {");
            $this->w->indent();
            $this->inScope(function () use ($catch, $type, $variable): void {
                if ($catch->variable !== null) {
                    $this->declareLocal($catch->variable, $type, $catch);
                }
                $this->fn->catchVariables[] = $variable;
                try {
                    if ($catch->when !== null) {
                        $this->w->line('if (!' . $this->wrap($this->expr($catch->when, Type::bool()), \SharpPhp\CodeGen\Prec::NOT) . ') {');
                        $this->w->line("    throw $variable;");
                        $this->w->line('}');
                    }
                    $this->statements($catch->body->statements);
                } finally {
                    array_pop($this->fn->catchVariables);
                }
            });
            $this->w->outdent();
            if ($this->flow->endReachable($catch->body)) {
                $outcomes[] = $this->assignments->save();
            }
        }
        $this->assignments->merge($outcomes);

        if ($s->finally !== null) {
            $afterTry = $this->assignments->save();
            $this->assignments->restore($before);
            $this->w->line('} finally {');
            $this->body($s->finally);
            $this->assignments->applyFinally($before, $afterTry);
        }
        $this->w->line('}');
    }

    private function catchTypes(string $fqn): string
    {
        $runtime = Names::RUNTIME_NAMESPACE . '\\';
        if (strcasecmp($fqn, $runtime . 'System\\Exception') === 0) {
            return '\\Throwable';
        }
        $types = [$this->classRef($fqn)];
        $relative = str_starts_with($fqn, $runtime) ? substr($fqn, strlen($runtime)) : null;
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
