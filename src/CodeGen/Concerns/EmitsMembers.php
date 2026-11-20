<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\CodeGen\Prec;
use SharpPhp\Compiler\CompileError;
use SharpPhp\Compiler\SemanticModel;
use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeResolver;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\Argument;
use SharpPhp\Syntax\BaseExpr;
use SharpPhp\Syntax\DeclarationExpr;
use SharpPhp\Syntax\ElementAccessExpr;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\InvocationExpr;
use SharpPhp\Syntax\LambdaExpr;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\PredefinedTypeExpr;
use SharpPhp\Syntax\ThisExpr;
use SharpPhp\Syntax\TypeRef;

/**
 * Name lookup, member access and invocation.
 *
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait EmitsMembers
{
    /** Members that are resolved at runtime when the receiver's type is unknown. */
    private const DYNAMIC_MEMBERS = ['Length', 'Count', 'Message', 'Key', 'Value', 'Keys', 'Values'];

    private const DYNAMIC_METHODS = [
        'ToString', 'Equals', 'CompareTo', 'GetHashCode', 'ToUpper', 'ToLower', 'Trim', 'TrimStart', 'TrimEnd',
        'Contains', 'StartsWith', 'EndsWith', 'IndexOf', 'LastIndexOf', 'Substring', 'Replace', 'Split',
        'PadLeft', 'PadRight', 'Insert', 'Remove', 'ToCharArray', 'Where', 'Select', 'Any', 'All', 'First',
        'FirstOrDefault', 'Last', 'LastOrDefault', 'Sum', 'Min', 'Max', 'Average', 'OrderBy',
        'OrderByDescending', 'ToList', 'ToArray', 'Reverse', 'Take', 'Skip', 'Distinct', 'Count', 'Aggregate',
        'ForEach',
    ];

    /** System.Object methods: without a receiver they mean this.X(), never a PHP function (gettype()). */
    private const OBJECT_METHODS = ['GetType', 'ToString', 'Equals', 'GetHashCode'];

    private const THROWABLE_GETTERS = ['Message' => 'getMessage', 'StackTrace' => 'getTraceAsString', 'InnerException' => 'getPrevious'];

    /**
     * @param 'value'|'invoke'|'target'|'assign'|'nameof' $mode
     */
    private function name(NameExpr $e, string $mode): Emitted
    {
        $name = $e->name;
        if ($this->model !== null && $name === SemanticModel::PLACEHOLDER) {
            $this->model->scope($this->file, $e, $this->scope->visible(), $this->class, $this->fn === null || $this->fn->isStatic, $this->ctx);
        }

        $local = $this->scope->lookup($name);
        if ($local !== null) {
            [$type, $crossed] = $local;
            if ($this->model !== null) {
                $declaring = $this->scope->declaringScope($name);
                $this->model->reference($this->file, $e, $name, 'local', $type, declaration: $declaring !== null ? $this->model->localDeclaration($this->file, $declaring->id, $name) : null);
            }
            if ($mode !== 'assign' && $mode !== 'nameof') {
                $this->requireAssigned($name, $e);
            }
            foreach ($crossed as $lambda) {
                $lambda->capture($name, $mode === 'assign');
            }

            return Emitted::value('$' . $name, $type, Prec::ATOM, $name);
        }

        if ($this->class !== null) {
            $member = $this->symbols->findMember($this->class, $name);
            if ($member !== null) {
                $this->model?->reference($this->file, $e, $name, $member->kind, $member->type, $member);

                return $this->ownMember($member, $e);
            }
        }

        $symbol = $this->resolver->findClass($name, $this->ctx, $e);
        // C# names are case-sensitive: url("/") calls the function url(), not the class URL that PHP
        // would also find under that name.
        if ($symbol !== null && $mode === 'invoke' && function_exists($name) && $symbol->shortName() !== $name) {
            $symbol = null;
        }
        if ($symbol !== null) {
            $result = $this->typeResult($symbol, $e->typeArgs);
            $this->model?->reference($this->file, $e, $name, 'type', $result->type, symbol: $symbol);

            return $result;
        }

        if ($mode === 'invoke' && function_exists($name)) {
            return new Emitted('\\' . $name, $this->symbols->functionReturnType($name), Prec::ATOM, 'function');
        }
        if ($mode === 'value' && defined($name) && !str_contains($name, '\\')) {
            return Emitted::value('\\' . $name, Type::mixed());
        }
        if ($mode === 'target' || $mode === 'nameof') {
            return new Emitted('', Type::mixed(), Prec::ATOM, 'namespace', namespace: $name);
        }

        throw $this->error('SP0103', "The name '$name' does not exist in the current context", $e);
    }

    private function ownMember(MemberSymbol $member, Node $at): Emitted
    {
        $this->requireAccessible($member, $at);
        if ($member->kind === 'method') {
            if (!$member->isStatic) {
                $this->requireInstance($member, $at);
            }

            return new Emitted($member->isStatic ? 'self::' : '$this->', $member->type, Prec::ATOM, 'method', member: $member);
        }
        if ($member->kind === 'const' || $member->kind === 'case') {
            return Emitted::value('self::' . $member->name, $member->type);
        }
        if ($member->isStaticAccessor) {
            return new Emitted('self::get_' . $member->name . '()', $member->type, member: $member, receiver: 'self::');
        }
        if ($member->isStatic) {
            return new Emitted('self::$' . $member->name, $member->type, member: $member);
        }
        $this->requireInstance($member, $at);

        return new Emitted('$this->' . $member->name, $member->type, member: $member);
    }

    /** private/protected members of project classes (PHP would only fail at runtime). */
    private function requireAccessible(MemberSymbol $member, Node $at): void
    {
        $violation = $this->access->accessViolation($member, $this->class);
        if ($violation !== null) {
            throw $this->violation($violation, $at);
        }
    }

    /**
     * Rejects assignments C# does not allow: get-only and init-only properties outside the
     * constructor, inaccessible setters, readonly fields.
     */
    private function requireWritable(MemberSymbol $member, Node $at, bool $initializer = false): void
    {
        $constructorOf = $this->fn !== null && $this->fn->isConstructor ? $this->class : null;
        $violation = $this->access->writeViolation($member, $this->class, $constructorOf, $initializer);
        if ($violation !== null) {
            throw $this->violation($violation, $at);
        }
    }


    private function requireInstance(MemberSymbol $member, Node $at): void
    {
        if ($this->fn === null || $this->fn->isStatic) {
            throw $this->error('SP0120', "An object reference is required for the non-static field, method, or property '{$member->owner->csharpName()}.{$member->name}'", $at);
        }
    }

    /** @param list<TypeRef> $typeArgs */
    private function typeResult(TypeSymbol $symbol, array $typeArgs): Emitted
    {
        $args = array_map(fn (TypeRef $r): Type => $this->resolveType($r), $typeArgs);

        return new Emitted($this->classRef($symbol->fqn), Type::class($symbol->fqn, $args), Prec::ATOM, 'type', $symbol);
    }

    /** Turns a lookup result into a usable value (or reports why it is not one). */
    private function valueOf(Emitted $result, Node $at): Emitted
    {
        return match ($result->kind) {
            'value' => $result,
            'method' => $this->methodGroup($result),
            'function' => Emitted::value($result->code . '(...)', Type::closure()),
            'type' => throw $this->error('SP0119', "'" . ($result->symbol?->csharpName() ?? $result->code) . "' is a type, which is not valid in the given context", $at),
            'namespace' => throw $this->namespaceError((string) $result->namespace, $at),
            default => throw $this->error('SP0175', "Use of keyword 'base' is not valid in this context", $at),
        };
    }

    /** A method used as a value (list.ForEach(Print)): a first-class callable. */
    private function methodGroup(Emitted $result): Emitted
    {
        $member = $result->member ?? throw new \LogicException('A method group has a member');

        return Emitted::value(
            $result->code . $member->name . '(...)',
            Type::closure([...array_map(static fn (ParameterSymbol $p): Type => $p->type, $member->params), $member->type]),
        );
    }

    private function namespaceError(string $dotted, Node $at): CompileError
    {
        $parts = explode('.', $dotted);
        if (!$this->symbols->namespaceExists(Names::phpNamespace($parts[0]))) {
            // Point at the unknown leftmost name, not at the member being accessed.
            $root = $at;
            while ($root instanceof MemberAccessExpr || $root instanceof InvocationExpr) {
                $root = $root instanceof MemberAccessExpr ? $root->target : $root->callee;
            }

            return $this->error('SP0103', "The name '{$parts[0]}' does not exist in the current context", $root);
        }
        if (count($parts) > 1) {
            $last = array_pop($parts);

            return $this->error('SP0234', "The type or namespace name '$last' does not exist in the namespace '" . implode('.', $parts) . "'", $at);
        }

        return $this->error('SP0118', "'$dotted' is a namespace but is used like a variable", $at);
    }

    /** Like expr(), but also allows type and namespace names (left side of a member access). */
    private function target(Expr $e): Emitted
    {
        return match (true) {
            $e instanceof NameExpr => $this->name($e, 'target'),
            $e instanceof MemberAccessExpr => $this->memberAccess($e, 'target'),
            $e instanceof PredefinedTypeExpr => $this->predefinedType($e),
            $e instanceof BaseExpr => $this->baseTarget($e),
            default => $this->expr($e),
        };
    }

    /**
     * C# "Color Color" rule: in "public Category Category => Category.Tech;" the name denotes both
     * a property and its type; a static member (enum case, const, static method) means the type.
     */
    private function colorColorType(Expr $target, string $memberName): ?Emitted
    {
        if (!$target instanceof NameExpr || $target->typeArgs !== []) {
            return null;
        }
        $local = $this->scope->lookup($target->name);
        $valueType = $local !== null
            ? $local[0]
            : ($this->class !== null ? $this->symbols->findMember($this->class, $target->name)?->type : null);
        if ($valueType === null || !$valueType->isClass()) {
            return null;
        }
        $symbol = $this->resolver->findClass($target->name, $this->ctx);
        if ($symbol === null || strcasecmp($symbol->fqn, (string) $valueType->class) !== 0) {
            return null;
        }
        $member = $this->symbols->findMember($symbol, $memberName) ?? $this->symbols->findMember($symbol, $memberName . '()');

        return $member !== null && $member->isStatic ? $this->typeResult($symbol, []) : null;
    }

    private function predefinedType(PredefinedTypeExpr $e): Emitted
    {
        $dotted = TypeResolver::KEYWORD_RUNTIME_CLASSES[$e->keyword] ?? null;
        $symbol = $dotted !== null ? $this->symbols->find(Names::phpClass($dotted)) : null;
        if ($symbol === null) {
            throw $this->error('SP0119', "'{$e->keyword}' is a type, which is not valid in the given context", $e);
        }

        return $this->typeResult($symbol, []);
    }

    private function baseTarget(BaseExpr $e): Emitted
    {
        if ($this->class === null || $this->class->baseClass === null) {
            throw $this->error('SP0175', "Use of keyword 'base' is not valid in this context", $e);
        }
        if ($this->fn === null || $this->fn->isStatic) {
            throw $this->error('SP1511', "Keyword 'base' is not available in a static method", $e);
        }

        return new Emitted('parent::', Type::class($this->class->baseClass), Prec::ATOM, 'base', $this->symbols->find($this->class->baseClass));
    }

    /**
     * @param 'value'|'target'|'assign' $mode
     */
    private function memberAccess(MemberAccessExpr $e, string $mode): Emitted
    {
        $result = $this->resolveMemberAccess($e, $mode);
        $this->recordReference($e, $e->name, $result);

        return $result;
    }

    /**
     * @param 'value'|'target'|'assign' $mode
     */
    private function resolveMemberAccess(MemberAccessExpr $e, string $mode): Emitted
    {
        $colorColor = $this->colorColorType($e->target, $e->name);
        if ($colorColor !== null) {
            $this->recordReceiver($e, $colorColor);

            return $this->staticMember($colorColor, $e);
        }
        $target = $this->target($e->target);
        $this->recordReceiver($e, $target);

        switch ($target->kind) {
            case 'namespace':
                $dotted = $target->namespace . '.' . $e->name;
                $symbol = $this->resolver->findClass($dotted, $this->ctx, $e);
                if ($symbol !== null) {
                    return $this->typeResult($symbol, $e->typeArgs);
                }
                if ($mode === 'target') {
                    return new Emitted('', Type::mixed(), Prec::ATOM, 'namespace', namespace: $dotted);
                }
                throw $this->namespaceError($dotted, $e);
            case 'type':
                return $this->staticMember($target, $e);
            case 'base':
                $member = $target->symbol !== null ? $this->symbols->findMember($target->symbol, $e->name) : null;
                if ($member === null) {
                    throw $this->error('SP0117', "'{$target->type->describe()}' does not contain a definition for '{$e->name}'", $e);
                }
                $this->requireAccessible($member, $e);
                if ($member->kind === 'method') {
                    return new Emitted('parent::', $member->type, Prec::ATOM, 'method', member: $member);
                }

                return new Emitted('$this->' . $e->name, $member->type, member: $member);
            case 'method':
            case 'function':
                $target = $this->valueOf($target, $e->target);
                break;
        }

        return $this->instanceMember($target, $e);
    }

    private function staticMember(Emitted $type, MemberAccessExpr $e): Emitted
    {
        $symbol = $type->symbol ?? throw new \LogicException('A type result has a symbol');
        $name = $e->name;
        $member = $this->symbols->findMember($symbol, $name) ?? $this->symbols->findMember($symbol, $name . '()');
        if ($member === null) {
            throw $this->error('SP0117', "'{$symbol->csharpName()}' does not contain a definition for '$name'", $e);
        }
        if (!$member->isStatic) {
            throw $this->error('SP0120', "An object reference is required for the non-static field, method, or property '{$symbol->csharpName()}.$name'", $e);
        }
        $this->requireAccessible($member, $e);
        $memberType = $member->type->substitute($member->owner->substitutionFor($type->type));

        return match ($member->kind) {
            'method' => new Emitted($type->code . '::', $memberType, Prec::ATOM, 'method', member: $member),
            'const', 'case' => Emitted::value("{$type->code}::$name", $memberType),
            default => $member->isStaticAccessor
                ? new Emitted("{$type->code}::get_$name()", $memberType, member: $member, receiver: "{$type->code}::")
                : new Emitted("{$type->code}::\$$name", $memberType, member: $member),
        };
    }

    private function instanceMember(Emitted $target, MemberAccessExpr $e): Emitted
    {
        $name = $e->name;
        $nc = $e->nullConditional;
        $t = $target->type;
        $arrow = $nc ? '?->' : '->';

        if ($t->isString()) {
            if ($name === 'Length') {
                return $this->nullSafe($target, $nc, static fn (string $c): string => "\\mb_strlen($c)", Type::int());
            }
            throw $this->error('SP1061', "'string' does not contain a definition for '$name'", $e);
        }
        if ($t->kind === 'array') {
            if ($name === 'Length' || $name === 'LongLength') {
                return $this->nullSafe($target, $nc, static fn (string $c): string => "\\count($c)", Type::int());
            }
            throw $this->error('SP1061', "'{$t->describe()}' does not contain a definition for '$name'", $e);
        }
        if ($t->isNumeric() || $t->isBool() || $t->isChar() || $t->kind === 'closure' || $t->isNull()) {
            throw $this->error('SP1061', "'{$t->describe()}' does not contain a definition for '$name'", $e);
        }

        $symbol = $this->symbolForType($t);
        if ($symbol !== null) {
            if (isset(self::THROWABLE_GETTERS[$name]) && $this->symbols->isThrowable($symbol->fqn)) {
                $getter = self::THROWABLE_GETTERS[$name];
                $type = $name === 'InnerException' ? Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Exception', [], true) : Type::string();

                return Emitted::value($this->wrap($target, Prec::ATOM) . "$arrow$getter()", $type);
            }

            $member = $this->symbols->findMember($symbol, $name);
            if ($member !== null && $member->isValue()) {
                if ($member->isStatic) {
                    throw $this->error('SP0176', "Member '{$symbol->csharpName()}.$name' cannot be accessed with an instance reference; qualify it with a type name instead", $e);
                }
                $this->requireAccessible($member, $e);
                $type = $member->type->substitute($member->owner->substitutionFor($t));

                return new Emitted($this->wrap($target, Prec::ATOM) . "$arrow$name", $nc ? $type->withNullable() : $type, member: $member);
            }
            $method = $this->findMethod($symbol, $name);
            if ($method !== null) {
                $this->requireAccessible($method, $e);

                return Emitted::value($this->wrap($target, Prec::ATOM) . "->$name(...)", Type::closure());
            }
            if ($member !== null) {
                throw $this->error('SP0176', "Member '{$symbol->csharpName()}.$name' cannot be accessed with an instance reference; qualify it with a type name instead", $e);
            }
            if (!$symbol->external) {
                throw $this->error('SP1061', "'{$symbol->csharpName()}' does not contain a definition for '$name'", $e);
            }

            return Emitted::value($this->wrap($target, Prec::ATOM) . "$arrow$name", Type::mixed());
        }

        if (in_array($name, self::DYNAMIC_MEMBERS, true)) {
            $dyn = $this->runtime('Dyn');

            return $this->nullSafe($target, $nc, static fn (string $c): string => "$dyn::get($c, '$name')", Type::mixed());
        }

        return Emitted::value($this->wrap($target, Prec::ATOM) . "$arrow$name", Type::mixed());
    }

    private function findMethod(TypeSymbol $symbol, string $name): ?MemberSymbol
    {
        $member = $this->symbols->findMember($symbol, $name);
        if ($member !== null && $member->kind === 'method') {
            return $member;
        }
        $member = $this->symbols->findMember($symbol, $name . '()');

        return $member !== null && $member->kind === 'method' ? $member : null;
    }

    private function symbolForType(Type $type): ?TypeSymbol
    {
        return $this->symbols->symbolForType($type);
    }

    /**
     * Applies $build to the target, guarding with a null check for "?." access.
     *
     * @param callable(string): string $build
     */
    private function nullSafe(Emitted $target, bool $nullConditional, callable $build, Type $type): Emitted
    {
        if (!$nullConditional) {
            return Emitted::value($build($target->code), $type);
        }
        if ($target->isSimple()) {
            return Emitted::value("{$target->code} === null ? null : " . $build($target->code), $type->withNullable(), Prec::TERNARY);
        }
        $temp = $this->temp($target);

        return Emitted::value("($temp = {$target->code}) === null ? null : " . $build($temp), $type->withNullable(), Prec::TERNARY);
    }

    private function temp(Node|Emitted $at): string
    {
        if ($this->fn === null) {
            throw $this->error('SP0133', 'The expression being assigned must be constant', $at instanceof Node ? $at : ($this->classDecl ?? throw new \LogicException('Outside a function and a class')));
        }

        return $this->currentFunction()->temp();
    }

    // ------------------------------------------------------------------
    // Invocation
    // ------------------------------------------------------------------

    private function invocation(InvocationExpr $e): Emitted
    {
        $callee = $e->callee;

        if ($callee instanceof NameExpr && in_array($callee->name, self::OBJECT_METHODS, true) && $this->class !== null
            && $this->scope->lookup($callee->name) === null && $this->symbols->findMember($this->class, $callee->name) === null) {
            $receiver = (new MemberAccessExpr((new ThisExpr())->at($callee), $callee->name, false, $callee->typeArgs))->at($callee);

            return $this->invocation((new InvocationExpr($receiver, $e->args))->at($e));
        }
        if ($callee instanceof NameExpr) {
            $result = $this->name($callee, 'invoke');

            return match ($result->kind) {
                'method' => $this->callMember($result->code, $result->member ?? throw new \LogicException('A method group has a member'), $e, null, $callee->typeArgs, null, $callee),
                'function' => Emitted::value($result->code . '(' . $this->arguments($e->args, $this->symbols->functionParams($callee->name), runtime: true)[0] . ')', $result->type),
                'value' => $this->callClosure($result, $e->args, $callee),
                default => throw $this->error('SP1955', "Non-invocable member '{$callee->name}' cannot be used like a method", $callee),
            };
        }

        if ($callee instanceof MemberAccessExpr) {
            $target = $this->colorColorType($callee->target, $callee->name) ?? $this->target($callee->target);
            $this->recordReceiver($callee, $target);

            if ($target->kind === 'namespace') {
                throw $this->namespaceError($target->namespace . '.' . $callee->name, $callee);
            }
            if ($target->kind === 'type') {
                $symbol = $target->symbol ?? throw new \LogicException('A type result has a symbol');
                $member = $this->findMethod($symbol, $callee->name);
                // PHP classes with __callStatic (Laravel's facades and models: Route::get, User::where)
                // answer static calls to any name.
                $magic = $symbol->external && $this->symbols->findMember($symbol, '__callStatic') !== null;
                if ($member === null && $magic) {
                    [$code] = $this->arguments($e->args, [], runtime: true);

                    return Emitted::value("{$target->code}::{$callee->name}($code)", Type::mixed());
                }
                if ($member === null) {
                    $other = $this->symbols->findMember($symbol, $callee->name);
                    throw $other !== null
                        ? $this->error('SP1955', "Non-invocable member '{$symbol->csharpName()}.{$callee->name}' cannot be used like a method", $callee)
                        : $this->error('SP0117', "'{$symbol->csharpName()}' does not contain a definition for '{$callee->name}'", $callee);
                }
                if (!$member->isStatic && !$magic) {
                    throw $this->error('SP0120', "An object reference is required for the non-static field, method, or property '{$symbol->csharpName()}.{$callee->name}'", $callee);
                }
                $this->requireAccessible($member, $callee);

                return $this->callMember($target->code . '::', $member, $e, $target->type, $callee->typeArgs, null, $callee);
            }
            if ($target->kind === 'base') {
                $member = $target->symbol !== null ? $this->findMethod($target->symbol, $callee->name) : null;
                if ($member === null) {
                    throw $this->error('SP0117', "'{$target->type->describe()}' does not contain a definition for '{$callee->name}'", $callee);
                }
                $this->requireAccessible($member, $callee);

                return $this->callMember('parent::', $member, $e, null, $callee->typeArgs, null, $callee);
            }
            if ($target->kind !== 'value') {
                $target = $this->valueOf($target, $callee->target);
            }

            return $this->callInstance($target, $callee, $e);
        }

        return $this->callClosure($this->expr($callee), $e->args, $callee);
    }

    /**
     * @param InvocationExpr $call the call: its arguments, and its position (the "(")
     * @param list<TypeRef> $typeArgs
     */
    private function callMember(string $prefix, MemberSymbol $member, InvocationExpr $call, ?Type $receiver, array $typeArgs, ?Type $elementHint, Node $at): Emitted
    {
        $args = $call->args;
        $this->model?->reference($this->file, $at, $member->name, 'method', $member->type, $member);
        $runtime = $member->owner->external;
        $subst = $receiver !== null ? $member->owner->substitutionFor($receiver) : [];
        $this->recordCall($call->line, $call->column, $args, $member->owner->shortName() . '.' . $member->name, $member->params, $member->type, $subst);
        // The note about missing .NET overloads is for the SharpPHP runtime library, not for other PHP code.
        $this->checkArguments($args, $member->params, $member->name, $member->displayName(), $at, runtime: self::isRuntimeLibrary($member->owner));
        foreach ($member->typeParams as $i => $param) {
            if (isset($typeArgs[$i])) {
                $subst[$param] = $this->resolveType($typeArgs[$i]);
            }
        }

        [$code, $values] = $this->arguments($args, $member->params, $subst, $elementHint, $runtime);

        foreach ($member->params as $i => $param) {
            if (isset($values[$i])) {
                $this->inferTypeArguments($param->type, $values[$i]->type, $member->typeParams, $subst);
            }
        }

        return $this->valueTypeDefault($member->name, Emitted::value($prefix . $member->name . '(' . $code . ')', $this->resultType($member, $subst, $values)), $elementHint);
    }

    /**
     * FirstOrDefault() and friends return default(T): 0 for a List<int>, not null. The runtime
     * does not know T (generics are erased), so the compiler supplies the value-type default.
     */
    private function valueTypeDefault(string $method, Emitted $call, ?Type $element): Emitted
    {
        if (!in_array($method, ['FirstOrDefault', 'LastOrDefault', 'SingleOrDefault', 'ElementAtOrDefault'], true)
            || $element === null || $element->nullable || !$element->isValueType()) {
            return $call;
        }

        return Emitted::value($this->wrap($call, Prec::COALESCE + 1) . ' ?? ' . $this->defaultValue($element)->code, $element, Prec::COALESCE);
    }

    /**
     * Binds method type parameters by matching a parameter type against an argument type:
     * T ~ int, List<T> ~ List<string>, Func<T, TResult> ~ closure(int, string).
     *
     * @param list<string> $names
     * @param array<string, Type> $subst
     */
    private function inferTypeArguments(Type $param, Type $arg, array $names, array &$subst): void
    {
        if ($param->kind === 'typeparam') {
            if (in_array($param->param, $names, true) && !isset($subst[$param->param]) && !$arg->isNull()) {
                $subst[$param->param] = $arg->withNullable(false);
            }

            return;
        }
        if ($param->kind === $arg->kind && $param->args !== [] && count($param->args) === count($arg->args)) {
            foreach ($param->args as $i => $inner) {
                $this->inferTypeArguments($inner, $arg->args[$i], $names, $subst);
            }
        } elseif ($param->kind === 'iterable' && $arg->kind !== 'iterable') {
            $this->inferTypeArguments($param->element(), $this->elementTypeOf($arg), $names, $subst);
        }
    }

    /**
     * @param array<string, Type> $subst
     * @param array<int, Emitted> $values
     */
    private function resultType(MemberSymbol $member, array $subst, array $values): Type
    {
        $type = $member->type->substitute($subst);

        // Task.WhenAll(tasks) of Task<T>s (or of a collection of them) has the result T[].
        if ($member->name === 'WhenAll' && strcasecmp($member->owner->fqn, self::TASK_CLASS) === 0) {
            $first = reset($values);
            $task = $first === false ? null : ($first->type->kind === 'class' && strcasecmp((string) $first->type->class, self::TASK_CLASS) === 0
                ? $first->type
                : $this->elementTypeOf($first->type));
            $element = $task !== null && $task->kind === 'class' && strcasecmp((string) $task->class, self::TASK_CLASS) === 0 ? ($task->args[0] ?? null) : null;

            return $element === null ? Type::class(self::TASK_CLASS) : Type::class(self::TASK_CLASS, [Type::array($element)]);
        }

        // Select(x => ...) produces a list of whatever the lambda returns.
        if ($member->name === 'Select' && $type->isClass()) {
            $selector = end($values);
            if ($selector !== false && $selector->type->kind === 'closure') {
                return Type::class((string) $type->class, [$selector->type->closureReturn()]);
            }
        }

        return $type;
    }

    /** target.Method(...): $call is the whole call, $callee its "target.Method" part. */
    private function callInstance(Emitted $target, MemberAccessExpr $callee, InvocationExpr $call): Emitted
    {
        $args = $call->args;
        $name = $callee->name;
        $nc = $callee->nullConditional;
        $t = $target->type;
        $recv = $this->wrap($target, Prec::ATOM);
        $arrow = $nc ? '?->' : '->';

        // object.GetType(), for every value (numbers and strings too).
        if ($name === 'GetType' && $args === [] && $t->kind !== 'closure') {
            $typeClass = $this->runtime('System\\Type');

            return $this->nullSafe($target, $nc, static fn (string $c): string => "$typeClass::fromValue($c)", Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Type'));
        }

        if ($t->isString()) {
            return $this->callHelper('System\\String_', $target, $callee, $call, 'string');
        }
        if ($t->kind === 'array' || $t->kind === 'iterable') {
            return $this->callHelper('System\\Linq\\Enumerable', $target, $callee, $call, $t->describe());
        }
        if ($t->isNumeric() || $t->isBool() || $t->isChar()) {
            $op = $this->runtime('Op');
            if ($name === 'ToString') {
                [$code] = $this->arguments($args, []);

                return Emitted::value($code === '' ? "$op::str({$target->code})" : "$op::format({$target->code}, $code)", Type::string());
            }
            $extension = $this->callExtension($target, $callee, $call);
            if ($extension !== null) {
                return $extension;
            }
            [$code] = $this->arguments($args, []);
            $dyn = $this->runtime('Dyn');

            return Emitted::value("$dyn::call({$target->code}, '$name'" . ($code !== '' ? ", $code" : '') . ')', Type::mixed());
        }
        if ($t->kind === 'closure') {
            if ($name === 'Invoke') {
                return $this->callClosure($target, $args, $callee);
            }

            return $this->callExtension($target, $callee, $call)
                ?? throw $this->error('SP1061', "'delegate' does not contain a definition for '$name'", $callee);
        }

        if ($t->kind === 'typeparam' && in_array($name, self::DYNAMIC_METHODS, true)) {
            [$code] = $this->arguments($args, []);
            $dyn = $this->runtime('Dyn');

            return $this->nullSafe($target, $nc, static fn (string $c): string => "$dyn::call($c, '$name'" . ($code !== '' ? ", $code" : '') . ')', Type::mixed());
        }

        $symbol = $this->symbolForType($t);
        if ($symbol !== null) {
            if ($symbol->isEnum() && $name === 'ToString' && $args === []) {
                return Emitted::value("$recv{$arrow}name", Type::string());
            }
            $member = $this->findMethod($symbol, $name);
            if ($member !== null) {
                if ($member->isStatic) {
                    throw $this->error('SP0176', "Member '{$symbol->csharpName()}.$name' cannot be accessed with an instance reference; qualify it with a type name instead", $callee);
                }
                $this->requireAccessible($member, $callee);

                return $this->callMember($recv . $arrow, $member, $call, $t, $callee->typeArgs, $this->elementTypeOf($t), $callee);
            }
            $property = $this->symbols->findMember($symbol, $name);
            if ($property !== null && $property->isValue() && $property->type->kind === 'closure') {
                return $this->callClosure(Emitted::value("$recv$arrow$name", $property->type), $args, $callee);
            }
            $fallback = $this->objectMethod($target, $name, $args) ?? $this->callExtension($target, $callee, $call);
            if ($fallback !== null) {
                return $fallback;
            }
            if (!$symbol->external) {
                throw $this->error('SP1061', "'{$symbol->csharpName()}' does not contain a definition for '$name'", $callee);
            }
            // A PHP class answers any name through __call.
            [$code] = $this->arguments($args, []);

            return Emitted::value("$recv$arrow$name($code)", Type::mixed());
        }

        $extension = $this->callExtension($target, $callee, $call);
        if ($extension !== null) {
            return $extension;
        }
        [$code] = $this->arguments($args, []);
        if (in_array($name, self::DYNAMIC_METHODS, true)) {
            $dyn = $this->runtime('Dyn');

            return $this->nullSafe($target, $nc, static fn (string $c): string => "$dyn::call($c, '$name'" . ($code !== '' ? ", $code" : '') . ')', Type::mixed());
        }

        return Emitted::value("$recv$arrow$name($code)", Type::mixed());
    }

    /**
     * System.Object methods every class has in C#.
     *
     * @param list<Argument> $args
     */
    private function objectMethod(Emitted $target, string $name, array $args): ?Emitted
    {
        $op = $this->runtime('Op');

        return match (true) {
            $name === 'ToString' && $args === [] => Emitted::value("$op::str({$target->code})", Type::string()),
            $name === 'Equals' && count($args) === 1 => Emitted::value("$op::equals({$target->code}, " . $this->arguments($args, [])[0] . ')', Type::bool()),
            $name === 'GetHashCode' && $args === [] => Emitted::value("\\spl_object_id({$target->code})", Type::int()),
            default => null,
        };
    }

    /**
     * A method of a string, an array or a sequence, which the runtime library implements as a static
     * helper taking the receiver first: text.ToUpper() => String_::ToUpper($text). Without such a
     * helper, an extension method in scope is called. $call is the whole call, $callee its
     * "text.ToUpper" part; $what names the receiver's type in messages ("string").
     */
    private function callHelper(string $class, Emitted $target, MemberAccessExpr $callee, InvocationExpr $call, string $what): Emitted
    {
        $symbol = $this->symbols->find(Names::RUNTIME_NAMESPACE . '\\' . $class);
        $member = $symbol !== null ? $this->findMethod($symbol, $callee->name) : null;
        if ($member === null || !$member->isStatic) {
            return $this->callExtension($target, $callee, $call)
                ?? throw $this->error('SP1061', "'$what' does not contain a definition for '{$callee->name}'", $callee);
        }

        return $this->callWithReceiver($this->runtime($class), $member, $target, $callee, $call, $what, runtime: true);
    }

    /**
     * receiver.Method(...) as an extension method in scope (see ExtensionMethods):
     * StringExtensions::Shout($text). Null when none of that name applies to the receiver, so that the
     * caller reports the missing member as C# does. Callers try it only after the receiver's own
     * methods, which win.
     */
    private function callExtension(Emitted $target, MemberAccessExpr $callee, InvocationExpr $call): ?Emitted
    {
        $found = $this->extensions->best($target->type, $this->ctx, $callee->name);
        if ($found === []) {
            return null;
        }
        if (count($found) > 1) {
            throw $this->error('SP0121', "The call is ambiguous between the following methods or properties: '{$found[0]->displayName()}' and '{$found[1]->displayName()}'", $callee);
        }
        $member = $found[0];
        $this->requireAccessible($member, $callee);

        return $this->callWithReceiver($this->classRef($member->owner->fqn), $member, $target, $callee, $call, $member->owner->shortName(), runtime: false);
    }

    /**
     * Calls a static method that takes the receiver as its first parameter, as runtime helpers and
     * extension methods do: Class::Method($receiver, ...$arguments). A null receiver of ?. gives null.
     *
     * Type parameters bound by the receiver (Enumerable::Where<TSource>(iterable<TSource> $source, ...))
     * come from it. Runtime helpers keep to that (resultType() knows their special cases); extension
     * methods, being project code, also take explicit type arguments and infer from the other
     * arguments, as callMember() does.
     *
     * @param string $classRef the class as the generated code names it
     * @param string $what what C# shows before the method's name: the receiver's type for a helper
     *                     ("string"), the class for an extension method ("StringExtensions")
     * @param bool $runtime whether the method belongs to the SharpPHP runtime library
     */
    private function callWithReceiver(string $classRef, MemberSymbol $member, Emitted $target, MemberAccessExpr $callee, InvocationExpr $call, string $what, bool $runtime): Emitted
    {
        $args = $call->args;
        $name = $member->name;
        $this->model?->reference($this->file, $callee, $callee->name, 'method', $member->type, $member);

        // The receiver is the first parameter, so C# code passes the others.
        $params = array_slice($member->params, 1);
        $subst = [];
        if ($member->typeParams !== [] && isset($member->params[0])) {
            $this->inferTypeArguments($member->params[0]->type, $target->type, $member->typeParams, $subst);
        }
        if (!$runtime) {
            foreach ($member->typeParams as $i => $param) {
                if (isset($callee->typeArgs[$i])) {
                    $subst[$param] = $this->resolveType($callee->typeArgs[$i]);
                }
            }
        }
        $this->recordCall($call->line, $call->column, $args, "$what.$name", $params, $member->type, $subst);
        $this->checkArguments($args, $params, $name, $this->signature("$what.$name", $params), $callee, runtime: $runtime);
        $elementType = $this->elementTypeOf($target->type);
        [$code, $values] = $this->arguments($args, $params, $subst, $elementType, runtime: $runtime);
        if (!$runtime) {
            foreach ($params as $i => $param) {
                if (isset($values[$i])) {
                    $this->inferTypeArguments($param->type, $values[$i]->type, $member->typeParams, $subst);
                }
            }
        }
        $type = $this->resultType($member, $subst, $values);

        return $this->valueTypeDefault($name, $this->nullSafe(
            $target,
            $callee->nullConditional,
            static fn (string $c): string => "$classRef::$name($c" . ($code !== '' ? ", $code" : '') . ')',
            $type,
        ), $callee->nullConditional ? null : $elementType);
    }

    /** @param list<Argument> $args */
    private function callClosure(Emitted $value, array $args, Node $at): Emitted
    {
        $t = $value->type;
        if (!$t->isUnknown() && $t->kind !== 'closure' && !($t->isClass() && strcasecmp((string) $t->class, 'Closure') === 0)) {
            throw $this->error('SP0149', "'{$t->describe()}' is not invocable (method name expected)", $at);
        }
        $params = [];
        if ($t->kind === 'closure') {
            foreach (array_slice($t->args, 0, -1) as $i => $paramType) {
                $params[] = new ParameterSymbol('arg' . $i, $paramType);
            }
            // PHP silently ignores extra arguments to a closure; C# rejects the call.
            if ($t->args !== [] && count($args) > count($params)) {
                throw $this->error('SP1593', "Delegate '{$this->delegateName($t)}' does not take " . count($args) . ' arguments', $at);
            }
        }
        [$code] = $this->arguments($args, $params);
        $callee = preg_match('/^\$[\w\x80-\xff]+$/', $value->code) === 1 ? $value->code : '(' . $value->code . ')';

        return Emitted::value("$callee($code)", $t->closureReturn());
    }

    /**
     * Rejects calls that C# would not compile: too many arguments, a missing required argument,
     * or an unknown parameter name. (PHP would silently drop extra arguments.)
     *
     * @param list<Argument> $args
     * @param list<ParameterSymbol> $params
     */
    private function checkArguments(array $args, array $params, string $name, string $signature, Node $at, bool $constructor = false, bool $runtime = false): void
    {
        $violation = $this->argumentMatcher->violation($args, $params, $name, $signature, $constructor, $runtime);
        if ($violation !== null) {
            throw $this->violation($violation, $at);
        }
    }

    /**
     * Records a call for parameter info in editors (only when analyzing for the language server).
     * It is recorded before its arguments are checked or emitted, so that a call being typed, whose
     * arguments are still wrong or missing, is known too.
     *
     * @param int $line line of the "(" that starts the arguments
     * @param int $column column of that "("
     * @param list<Argument> $args the arguments written so far
     * @param string $name the callee as C# shows it ("Console.WriteLine", or "Random" for a constructor)
     * @param list<ParameterSymbol> $params
     * @param ?Type $returns the result type; null for a constructor
     * @param array<string, Type> $subst type arguments known at the call (T is int for a List<int>)
     */
    private function recordCall(int $line, int $column, array $args, string $name, array $params, ?Type $returns, array $subst = []): void
    {
        if ($this->model === null) {
            return;
        }
        $params = array_map(
            static fn (ParameterSymbol $p): ParameterSymbol => new ParameterSymbol($p->name, $p->type->substitute($subst), $p->modifier, $p->optional, $p->numeric),
            $params,
        );
        $arguments = array_map(static fn (Argument $a): array => ['line' => $a->line, 'column' => $a->column, 'name' => $a->name], $args);
        $this->model->call($this->file, $line, $column, $name, $params, $returns?->substitute($subst), $arguments);
    }

    private static function isRuntimeLibrary(TypeSymbol $symbol): bool
    {
        return $symbol->external && str_starts_with($symbol->fqn, Names::RUNTIME_NAMESPACE . '\\');
    }

    /** @param list<ParameterSymbol> $params "Program.Sq(int)" */
    private function signature(string $name, array $params): string
    {
        return $name . '(' . ParameterSymbol::describeTypes($params) . ')';
    }

    /** Func<int, string> / Action<int> for a closure type. */
    private function delegateName(Type $closure): string
    {
        return $closure->args === [] ? 'Action' : $closure->delegateName();
    }

    /**
     * @param list<Argument> $args
     * @param list<ParameterSymbol> $params
     * @param array<string, Type> $subst
     * @return array{0: string, 1: array<int, Emitted>}
     */
    private function arguments(array $args, array $params, array $subst = [], ?Type $elementHint = null, bool $runtime = false): array
    {
        $codes = [];
        $values = [];
        $last = $params !== [] ? $params[array_key_last($params)] : null;

        foreach ($args as $i => $arg) {
            $param = null;
            if ($arg->name !== null) {
                foreach ($params as $candidate) {
                    if ($candidate->name === $arg->name) {
                        $param = $candidate;
                    }
                }
            } else {
                $param = $params[$i] ?? ($last?->modifier === 'params' ? $last : null);
            }

            $expected = $param?->type->substitute($subst);
            if ($param?->modifier === 'params' && $expected?->kind === 'array') {
                $expected = $expected->element();
            }
            if ($arg->value instanceof LambdaExpr && $elementHint !== null && count($arg->value->params) === 1
                && ($expected === null || $expected->kind !== 'closure' || $expected->args === [] || $expected->args[0]->isUnknown())) {
                $expected = Type::closure([$elementHint, $expected?->closureReturn() ?? Type::mixed()]);
            }

            if ($arg->value instanceof DeclarationExpr) {
                $declType = $arg->value->type !== null
                    ? $this->resolveType($arg->value->type)
                    : ($expected?->withNullable(false) ?? Type::mixed());
                $this->declareLocal($arg->value->name, $declType, $arg->value);
                $value = Emitted::value('$' . $arg->value->name, $declType);
            } elseif ($arg->modifier === 'out' && $arg->value instanceof NameExpr && $arg->value->name === '_' && $this->scope->lookup('_') === null) {
                // out _: a discard; PHP passes a variable by reference, so a fresh one nobody reads.
                $value = Emitted::value($this->currentFunction()->temp('discard'), $expected ?? Type::mixed());
            } elseif ($arg->modifier === 'ref' || $arg->modifier === 'out') {
                $value = $this->place($arg->value);
                if ($value->member?->isStaticAccessor) {
                    throw $this->error('SP0206', 'A property or indexer may not be passed as an out or ref parameter', $arg->value);
                }
                if ($arg->modifier === 'out') {
                    $this->markAssigned($arg->value);
                } elseif ($arg->value instanceof NameExpr) {
                    $this->requireAssigned($arg->value->name, $arg->value);
                }
            } else {
                $value = $this->expr($arg->value, $expected);
                // Sum(numbers) for Sum(params int[] xs): an array passed alone is the params array itself.
                $isParamsArray = $param?->modifier === 'params' && $arg->name === null && $value->type->kind === 'array'
                    && count($args) === count($params) && $i === count($params) - 1;
                if ($isParamsArray) {
                    $array = $this->converted($value, Type::array($expected ?? Type::mixed()), $arg->value, $i + 1, $runtime);
                    $value = Emitted::value('...' . $array->code, $array->type);
                } elseif ($expected !== null) {
                    $value = $this->converted($value, $expected, $arg->value, $i + 1, $runtime);
                }
                if ($param?->numeric) {
                    $value = $this->charToInt($value);
                }
            }

            $values[$i] = $value;
            $codes[] = ($arg->name !== null ? $arg->name . ': ' : '') . $value->code;
        }

        return [implode(', ', $codes), $values];
    }

    // ------------------------------------------------------------------
    // Indexers
    // ------------------------------------------------------------------

    private function elementAccess(ElementAccessExpr $e, bool $write = false): Emitted
    {
        if (count($e->indexes) !== 1) {
            throw $this->error('SP0022', 'Wrong number of indices inside []; expected 1', $e);
        }
        if ($write && $e->nullConditional) {
            throw $this->error('SP0131', 'The left-hand side of an assignment must be a variable, property or indexer', $e);
        }

        $target = $this->expr($e->target);
        $t = $target->type;
        $symbol = $this->symbolForType($t);
        $offsetGet = $symbol !== null ? $this->findMethod($symbol, 'offsetGet') : null;
        $keyType = $offsetGet !== null && isset($offsetGet->params[0])
            ? $offsetGet->params[0]->type->substitute($symbol->substitutionFor($t))
            : Type::int();
        $index = $this->expr($e->indexes[0], $keyType);

        if ($t->isString()) {
            if ($write) {
                throw $this->error('SP0200', "Property or indexer 'string.this[int]' cannot be assigned to -- it is read only", $e);
            }

            return $this->nullSafe($target, $e->nullConditional, static fn (string $c): string => "\\mb_substr($c, {$index->code}, 1)", Type::char());
        }
        if ($t->isNumeric() || $t->isBool() || $t->isChar() || $t->kind === 'closure') {
            throw $this->error('SP0021', "Cannot apply indexing with [] to an expression of type '{$t->describe()}'", $e);
        }

        $type = match (true) {
            $t->kind === 'array' => $t->element(),
            $offsetGet !== null => $offsetGet->type->substitute($symbol->substitutionFor($t)),
            default => Type::mixed(),
        };

        if ($write && $t->kind === 'array' && !$t->isPhpArray() && $target->isRepeatablePath()) {
            // A C# array has a fixed length, so writing outside it throws IndexOutOfRangeException, while
            // PHP would add the element (a read outside it already throws, through the error handler).
            // The index is checked against the array itself: $a[Op::index($a, $i)] = ... PHP arrays (from
            // PHP code) are maps that take new keys, and are written as they are.
            $index = Emitted::value($this->runtime('Op') . "::index({$target->code}, {$index->code})", $index->type);
        }

        return $this->nullSafe(
            $target,
            $e->nullConditional,
            fn (string $c): string => ($c === $target->code ? $this->wrap($target, Prec::ATOM) : $c) . '[' . $index->code . ']',
            $type,
        );
    }
}
