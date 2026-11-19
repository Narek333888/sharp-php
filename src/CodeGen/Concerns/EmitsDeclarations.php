<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\FunctionContext;
use SharpPhp\Compiler\Declarator;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\Accessor;
use SharpPhp\Syntax\ArrayCreationExpr;
use SharpPhp\Syntax\BinaryExpr;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\ConditionalExpr;
use SharpPhp\Syntax\DefaultExpr;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\FieldDecl;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\MethodDecl;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\NameOfExpr;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\NodeFinder;
use SharpPhp\Syntax\Parameter;
use SharpPhp\Syntax\PropertyDecl;
use SharpPhp\Syntax\ReturnStmt;
use SharpPhp\Syntax\TypeDecl;
use SharpPhp\Syntax\TypeOfExpr;
use SharpPhp\Syntax\UnaryExpr;
use SharpPhp\Syntax\YieldStmt;

/**
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait EmitsDeclarations
{
    private function emitType(TypeDecl $decl): void
    {
        $this->class = $this->declarator->symbolOf($decl);
        $this->classDecl = $decl;
        $this->w->sourceLine = $decl->line;

        try {
            match ($decl->kind) {
                'enum' => $this->emitEnum($decl, $this->class),
                'interface' => $this->emitInterface($decl, $this->class),
                default => $this->emitClass($decl, $this->class),
            };
        } finally {
            $this->class = null;
            $this->classDecl = null;
        }
    }

    private function emitTemplateDoc(array $typeParams): void
    {
        if ($typeParams === []) {
            return;
        }
        $this->w->line('/**');
        foreach ($typeParams as $name) {
            $this->w->line(" * @template $name");
        }
        $this->w->line(' */');
    }

    // ------------------------------------------------------------------
    // Classes, structs and records
    // ------------------------------------------------------------------

    private function emitClass(TypeDecl $decl, TypeSymbol $symbol): void
    {
        $modifiers = $decl->modifiers;
        $prefix = match (true) {
            in_array('abstract', $modifiers, true) => 'abstract ',
            in_array('sealed', $modifiers, true), in_array('static', $modifiers, true), $decl->kind === 'struct' => 'final ',
            default => '',
        };
        $header = $prefix . 'class ' . Names::shortName($symbol->fqn);
        if ($symbol->baseClass !== null) {
            $header .= ' extends ' . $this->classRef($symbol->baseClass);
        }
        if ($symbol->interfaces !== []) {
            $header .= ' implements ' . implode(', ', array_map(fn (string $i): string => $this->classRef($i), $symbol->interfaces));
        }

        $this->emitTemplateDoc($decl->typeParams);
        $this->w->line($header);
        $this->w->line('{');
        $this->w->indent();

        $consts = $fields = $properties = $methods = [];
        $constructor = null;
        $staticConstructor = null;
        foreach ($decl->members as $member) {
            match (true) {
                $member instanceof FieldDecl => $member->isConst ? $consts[] = $member : $fields[] = $member,
                $member instanceof PropertyDecl => $properties[] = $member,
                $member instanceof MethodDecl && $member->kind === 'constructor' => $constructor = $member,
                $member instanceof MethodDecl && $member->kind === 'static_constructor' => $staticConstructor = $member,
                $member instanceof MethodDecl => $methods[] = $member,
                default => null,
            };
        }

        /** @var list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits */
        $instanceInits = [];
        /** @var list<array{0: string, 1: Expr, 2: Type, 3: int}> $staticInits */
        $staticInits = [];

        $sections = 0;
        foreach ([$consts, $fields] as $group) {
            if ($group === []) {
                continue;
            }
            if ($sections++ > 0) {
                $this->w->blank();
            }
            foreach ($group as $field) {
                $this->emitField($field, $instanceInits, $staticInits);
            }
        }

        foreach ($properties as $property) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->emitProperty($property, $instanceInits, $staticInits);
        }

        if ($constructor !== null || $instanceInits !== [] || $decl->recordParams !== []) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->emitConstructor($constructor, $decl, $symbol, $instanceInits);
        }

        foreach ($methods as $method) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->emitMethod($method, false);
        }

        if ($symbol->isRecord && !isset($symbol->members['ToString']) && $decl->recordParams !== []) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->emitRecordToString($decl);
        }

        if (isset($symbol->members['ToString']) || ($symbol->isRecord && $decl->recordParams !== [])) {
            $toString = $symbol->members['ToString'] ?? null;
            if ($toString === null || ($toString->kind === 'method' && !$toString->isStatic && $toString->params === [])) {
                $this->w->blank();
                $this->w->line('public function __toString(): string');
                $this->w->line('{');
                $this->w->line('    return $this->ToString();');
                $this->w->line('}');
            }
        }

        if ($staticInits !== [] || $staticConstructor !== null) {
            $this->w->blank();
            $this->emitStaticInitializer($staticInits, $staticConstructor);
            $this->staticInitCalls[] = Names::shortName($symbol->fqn) . '::__sharpStatic();';
        }

        $this->w->outdent();
        $this->w->line('}');
    }

    /**
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $staticInits
     */
    private function emitField(FieldDecl $field, array &$instanceInits, array &$staticInits): void
    {
        $this->w->sourceLine = $field->line;
        $visibility = Declarator::visibility($field->modifiers);
        $static = in_array('static', $field->modifiers, true);
        $readonly = in_array('readonly', $field->modifiers, true) && !$static;

        foreach ($field->declarators as $declarator) {
            $this->w->sourceLine = $declarator->line;
            $member = $this->class->members[$declarator->name];
            $type = $member->type;

            if ($field->isConst) {
                if ($declarator->init === null) {
                    throw $this->error('SP0145', 'A const field requires a value to be provided', $declarator);
                }
                $value = $this->constantValue($declarator->init, $type);
                $decl = $type->isUnknown() ? '' : $this->typeDecl($type) . ' ';
                $this->w->line("$visibility const {$decl}{$declarator->name} = $value;");
                continue;
            }

            [$phpType, $default] = $this->storageFor($type, $declarator->init, $readonly, $static, '$this->' . $declarator->name, 'self::$' . $declarator->name, $instanceInits, $staticInits, $declarator->line);
            $line = $visibility . ($static ? ' static' : '') . ($readonly ? ' readonly' : '') . " $phpType \${$declarator->name}";
            $this->w->line($line . ($default !== null ? " = $default" : '') . ';');
        }
    }

    /**
     * Decides the PHP type and default value of a field or auto-property and queues
     * non-constant initializers for the constructor / static initializer.
     *
     * @return array{0: string, 1: ?string}
     */
    private function storageFor(Type $type, ?Expr $init, bool $readonly, bool $static, string $instanceTarget, string $staticTarget, array &$instanceInits, array &$staticInits, int $line): array
    {
        $phpType = (string) $this->typeDecl($type);

        if ($init !== null) {
            if (!$readonly && $this->isConstantExpression($init)) {
                return [$phpType, $this->constantValue($init, $type)];
            }
            if ($static) {
                $staticInits[] = [$staticTarget, $init, $type, $line];
            } else {
                $instanceInits[] = [$instanceTarget, $init, $type, $line];
            }

            return [$phpType, null];
        }

        if ($readonly) {
            return [$phpType, null];
        }

        return match (true) {
            $type->isInt() && !$type->nullable => [$phpType, '0'],
            $type->isFloat() && !$type->nullable => [$phpType, '0.0'],
            $type->isBool() && !$type->nullable => [$phpType, 'false'],
            default => [(string) $this->typeDecl($type->withNullable()), 'null'],
        };
    }

    private function emitProperty(PropertyDecl $property, array &$instanceInits, array &$staticInits): void
    {
        $this->w->sourceLine = $property->line;
        $member = $this->class->members[$property->name];
        $type = $member->type;
        $visibility = Declarator::visibility($property->modifiers);
        $static = in_array('static', $property->modifiers, true);
        $abstract = in_array('abstract', $property->modifiers, true);
        $name = $property->name;

        $get = $set = null;
        foreach ($property->accessors as $accessor) {
            if ($accessor->kind === 'get') {
                $get = $accessor;
            } else {
                $set = $accessor;
            }
        }
        $isAuto = $property->exprBody === null && array_reduce(
            $property->accessors,
            static fn (bool $carry, Accessor $a): bool => $carry && $a->body === null && $a->exprBody === null,
            true,
        );
        // get-only auto-properties are settable only inside the class (like C# constructor assignment).
        $setVisibility = $set !== null ? Declarator::visibility($set->modifiers, $visibility) : ($isAuto ? 'private' : $visibility);
        $asymmetric = $this->asymmetricVisibility($visibility, $setVisibility);
        $phpType = (string) $this->typeDecl($type);

        if ($abstract) {
            if (!$isAuto) {
                throw $this->error('SP0500', "'$name' cannot declare a body because it is marked abstract", $property);
            }
            $hooks = ($get !== null ? ' get;' : '') . ($set !== null ? ' set;' : '');
            $this->w->line("abstract $visibility $phpType \$$name {{$hooks} }");

            return;
        }

        if ($isAuto) {
            if ($static) {
                [$phpType, $default] = $this->storageFor($type, $property->init, false, true, '', 'self::$' . $name, $instanceInits, $staticInits, $property->line);
                $this->w->line("$visibility static $phpType \$$name" . ($default !== null ? " = $default" : '') . ';');

                return;
            }
            [$phpType, $default] = $this->storageFor($type, $property->init, false, false, '$this->' . $name, '', $instanceInits, $staticInits, $property->line);
            $this->w->line("$visibility$asymmetric $phpType \$$name" . ($default !== null ? " = $default" : '') . ';');

            return;
        }

        if ($static) {
            throw $this->error('SP0201', "Static properties with accessor bodies are not supported ('$name'); use a static method instead", $property);
        }
        if ($property->init !== null) {
            throw $this->error('SP8050', "Only auto-implemented properties can have initializers ('$name')", $property);
        }

        $this->w->line("$visibility$asymmetric $phpType \$$name {");
        $this->w->indent();

        if ($property->exprBody !== null) {
            $code = $this->inFunction(new FunctionContext(false, $type, $this->class->typeParamTypes), fn (): string => $this->convert($property->exprBody, $type)->code);
            $this->w->line("get => $code;");
        }
        foreach ($property->accessors as $accessor) {
            if ($accessor->body === null && $accessor->exprBody === null) {
                throw $this->error('SP0202', "Mixing auto-implemented and custom accessors is not supported ('$name')", $accessor);
            }
            $this->w->sourceLine = $accessor->line;
            $isGet = $accessor->kind === 'get';
            $context = new FunctionContext(false, $isGet ? $type : Type::void(), $this->class->typeParamTypes);
            $this->inFunction($context, function () use ($accessor, $isGet, $type, $name): void {
                $this->inScope(function () use ($accessor, $isGet, $type, $name): void {
                    if (!$isGet) {
                        $this->scope->declare('value', $type);
                    }
                    if ($accessor->exprBody !== null) {
                        $code = ($isGet ? $this->convert($accessor->exprBody, $type) : $this->expr($accessor->exprBody))->code;
                        if ($isGet) {
                            $this->w->line("get => $code;");
                        } else {
                            $this->w->line('set {');
                            $this->w->line("    $code;");
                            $this->w->line('}');
                        }

                        return;
                    }
                    if ($isGet && $this->endReachable($accessor->body)) {
                        throw $this->error('SP0161', "'{$this->class->csharpName()}.$name.get': not all code paths return a value", $accessor);
                    }
                    $this->w->line($isGet ? 'get {' : 'set {');
                    $this->w->indent();
                    $this->statements($accessor->body->statements);
                    $this->w->outdent();
                    $this->w->line('}');
                });
            });
        }

        $this->w->outdent();
        $this->w->line('}');
    }

    private function asymmetricVisibility(string $visibility, string $setVisibility): string
    {
        $rank = ['public' => 3, 'protected' => 2, 'private' => 1];

        return $rank[$setVisibility] < $rank[$visibility] ? " $setVisibility(set)" : '';
    }

    /**
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits
     */
    private function emitConstructor(?MethodDecl $constructor, TypeDecl $decl, TypeSymbol $symbol, array $instanceInits): void
    {
        $this->w->sourceLine = $constructor?->line ?? $decl->line;
        $context = new FunctionContext(false, Type::void(), $symbol->typeParamTypes, isConstructor: true);

        $this->inFunction($context, function () use ($constructor, $decl, $symbol, $instanceInits): void {
            $this->inScope(function () use ($constructor, $decl, $symbol, $instanceInits): void {
                $visibility = $constructor !== null ? Declarator::visibility($constructor->modifiers) : 'public';

                if ($decl->recordParams !== []) {
                    // Positional record: promoted readonly properties.
                    $params = [];
                    foreach ($decl->recordParams as $param) {
                        $type = $symbol->members[$param->name]->type;
                        $default = $param->default !== null ? ' = ' . $this->constantValue($param->default, $type) : '';
                        $params[] = 'public readonly ' . $this->typeDecl($type) . ' $' . $param->name . $default;
                        $this->scope->declare($param->name, $type);
                    }
                    $this->w->line("$visibility function __construct(");
                    foreach ($params as $param) {
                        $this->w->line("    $param,");
                    }
                    $this->w->line(') {');
                } else {
                    $this->w->line("$visibility function __construct(" . $this->parameters($constructor?->params ?? []) . ')');
                    $this->w->line('{');
                }

                $this->w->indent();
                $this->emitConstructorBody($constructor, $decl, $symbol, $instanceInits);
                $this->w->outdent();
                $this->w->line('}');
            });
        });
    }

    /**
     * Field initializers, then the base constructor call, then the body (C# order).
     *
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits
     */
    private function emitConstructorBody(?MethodDecl $constructor, TypeDecl $decl, TypeSymbol $symbol, array $instanceInits): void
    {
        foreach ($instanceInits as [$target, $init, $type, $line]) {
            $this->w->sourceLine = $line;
            $this->w->line("$target = " . $this->convert($init, $type)->code . ';');
        }

        $this->w->sourceLine = $constructor?->line ?? $decl->line;
        if ($constructor?->initializer === 'this') {
            throw $this->error('SP0111', "Constructor chaining with ': this(...)' is not supported", $constructor);
        }
        if ($constructor?->initializer === 'base') {
            if ($symbol->baseClass === null) {
                throw $this->error('SP0516', "'{$decl->name}' has no base class", $constructor);
            }
            $baseParams = $this->baseConstructorParams($symbol);
            if ($baseParams !== null) {
                $this->checkArguments($constructor->initializerArgs, $baseParams, Names::csharpName((string) $symbol->baseClass), $this->baseConstructorSignature($symbol, $baseParams), $constructor, constructor: true);
            }
            [$args] = $this->arguments($constructor->initializerArgs, $baseParams ?? []);
            $this->w->line("parent::__construct($args);");
        } elseif ($symbol->baseClass !== null && $this->symbols->hasConstructor($symbol->baseClass)) {
            $baseParams = $this->baseConstructorParams($symbol);
            if ($baseParams !== null) {
                $this->checkArguments([], $baseParams, Names::csharpName((string) $symbol->baseClass), $this->baseConstructorSignature($symbol, $baseParams), $constructor ?? $decl, constructor: true);
            }
            $this->w->line('parent::__construct();');
        }

        if ($constructor !== null) {
            $this->methodBody($constructor, Type::void());
        }
    }

    /** @return list<\SharpPhp\Semantics\ParameterSymbol>|null */
    private function baseConstructorParams(TypeSymbol $symbol): ?array
    {
        $base = $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null;

        return $base !== null ? $this->symbols->constructorParams($base) : null;
    }

    /** @param list<\SharpPhp\Semantics\ParameterSymbol> $params */
    private function baseConstructorSignature(TypeSymbol $symbol, array $params): string
    {
        $base = Names::csharpName((string) $symbol->baseClass);

        return $this->signature($base . '.' . substr($base, (int) strrpos('.' . $base, '.')), $params);
    }

    /**
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $staticInits
     */
    private function emitStaticInitializer(array $staticInits, ?MethodDecl $staticConstructor): void
    {
        $this->w->line('/** @internal Runs static field initializers and the static constructor. */');
        $this->w->line('public static function __sharpStatic(): void');
        $this->w->line('{');
        $this->w->indent();
        $this->inFunction(new FunctionContext(true, Type::void(), isConstructor: true), function () use ($staticInits, $staticConstructor): void {
            $this->inScope(function () use ($staticInits, $staticConstructor): void {
                foreach ($staticInits as [$target, $init, $type, $line]) {
                    $this->w->sourceLine = $line;
                    $this->w->line("$target = " . $this->convert($init, $type)->code . ';');
                }
                if ($staticConstructor !== null) {
                    $this->methodBody($staticConstructor, Type::void());
                }
            });
        });
        $this->w->outdent();
        $this->w->line('}');
    }

    private function emitMethod(MethodDecl $method, bool $inInterface): void
    {
        $this->w->sourceLine = $method->line;
        $member = $this->class->members[$method->name];
        $modifiers = $method->modifiers;
        $static = in_array('static', $modifiers, true);
        $abstract = in_array('abstract', $modifiers, true);
        $typeParams = $this->declarator->typeParamsOf($method, $this->ctx, $this->class->typeParamTypes);
        $isGenerator = $method->body !== null && NodeFinder::contains($method->body, static fn (Node $n): bool => $n instanceof YieldStmt);

        if ($inInterface && ($method->body !== null || $method->exprBody !== null)) {
            throw $this->error('SP8701', 'Default interface methods are not supported', $method);
        }
        if (!$inInterface && !$abstract && $method->body === null && $method->exprBody === null) {
            throw $this->error('SP0501', "'{$method->name}' must declare a body because it is not marked abstract", $method);
        }

        $this->emitTemplateDoc($method->typeParams);
        if (in_array('override', $modifiers, true)) {
            $base = $this->class->baseClass !== null ? $this->symbols->find($this->class->baseClass) : null;
            $overridden = $base !== null ? $this->findMethod($base, $method->name) : null;
            if ($overridden !== null) {
                $this->w->line('#[\\Override]');
            } elseif (!in_array($method->name, ['ToString', 'Equals', 'GetHashCode'], true)) {
                // ToString/Equals/GetHashCode override System.Object, which has no PHP counterpart.
                throw $this->error('SP0115', "'{$this->class->csharpName()}.{$method->name}()': no suitable method found to override", $method);
            }
        }

        $context = new FunctionContext($static, $member->type, $typeParams);
        $this->inFunction($context, function () use ($method, $member, $modifiers, $static, $abstract, $inInterface, $isGenerator): void {
            $this->inScope(function () use ($method, $member, $modifiers, $static, $abstract, $inInterface, $isGenerator): void {
                $visibility = $inInterface ? 'public' : $member->visibility;
                $prefix = ($abstract && !$inInterface ? 'abstract ' : '')
                    . (in_array('sealed', $modifiers, true) ? 'final ' : '')
                    . $visibility . ($static ? ' static' : '');
                $params = $this->parameters($method->params, $inInterface ? null : $this->inheritedMethod($method->name));
                $return = $isGenerator ? 'iterable' : $this->typeDecl($member->type);
                $signature = "$prefix function {$method->name}($params)" . ($return !== null ? ": $return" : '');

                if ($method->body === null && $method->exprBody === null) {
                    $this->w->line($signature . ';');

                    return;
                }

                if ($method->body !== null && !$isGenerator && $member->type->kind !== 'void' && $this->endReachable($method->body)) {
                    $params = implode(', ', array_map(static fn ($p): string => $p->type->describe(), $member->params));
                    throw $this->error('SP0161', "'{$this->class->csharpName()}.{$method->name}($params)': not all code paths return a value", $method);
                }

                $this->w->line($signature);
                $this->w->line('{');
                $this->w->indent();
                $this->methodBody($method, $member->type);
                $this->w->outdent();
                $this->w->line('}');
            });
        });
    }

    private function methodBody(MethodDecl $method, Type $returnType): void
    {
        if ($method->exprBody !== null) {
            $this->w->sourceLine = $method->exprBody->line;
            $value = $returnType->kind === 'void' ? $this->expr($method->exprBody) : $this->convert($method->exprBody, $returnType);
            $this->w->line(($returnType->kind === 'void' ? '' : 'return ') . $value->code . ';');

            return;
        }
        if ($method->body !== null) {
            $this->statements($method->body->statements);
        }
    }

    /** The base class or interface method that $name overrides or implements, if any. */
    private function inheritedMethod(string $name): ?\SharpPhp\Semantics\MemberSymbol
    {
        $candidates = [...($this->class->baseClass !== null ? [$this->class->baseClass] : []), ...$this->class->interfaces];
        foreach ($candidates as $fqn) {
            $symbol = $this->symbols->find($fqn);
            $method = $symbol !== null ? $this->findMethod($symbol, $name) : null;
            if ($method !== null) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Emits a parameter list and declares the parameters in the current scope.
     *
     * When the method implements a generic declaration (Save(T item) implemented as
     * Save(User item)), the PHP type comes from the inherited declaration: PHP requires
     * parameter types to be at least as wide as the parent's.
     *
     * @param list<Parameter> $params
     */
    private function parameters(array $params, ?\SharpPhp\Semantics\MemberSymbol $inherited = null): string
    {
        $parts = [];
        foreach ($params as $i => $param) {
            $type = $this->resolveType($param->type);
            $localType = $type;
            $parentType = $inherited?->params[$i]->type ?? null;
            if ($parentType !== null && ($parentType->kind === 'typeparam' || $parentType->isUnknown())) {
                $type = $parentType;
            }
            $prefix = '';
            $default = '';

            if ($param->modifier === 'params') {
                if ($type->kind !== 'array') {
                    throw $this->error('SP0225', 'The params parameter must be a single dimensional array', $param);
                }
                $type = $type->element();
                $prefix = '...';
            } elseif ($param->modifier === 'ref') {
                $prefix = '&';
            } elseif ($param->modifier === 'out') {
                $prefix = '&';
                $type = $type->withNullable();
            }

            if ($param->default !== null) {
                if ($param->default instanceof LiteralExpr && $param->default->kind === 'null') {
                    $type = $type->withNullable();
                }
                $default = ' = ' . $this->constantValue($param->default, $type);
            }

            $decl = $this->typeDecl($type);
            $parts[] = ($decl !== null ? $decl . ' ' : '') . $prefix . '$' . $param->name . $default;
            $this->declareLocal($param->name, $localType, $param);
        }

        return implode(', ', $parts);
    }

    private function emitRecordToString(TypeDecl $decl): void
    {
        $this->w->sourceLine = $decl->line;
        $op = $this->runtime('Op');
        $code = '';
        $text = $decl->name . ' { ';
        foreach ($decl->recordParams as $i => $param) {
            $text .= ($i > 0 ? ', ' : '') . $param->name . ' = ';
            $code .= self::phpString($text) . " . $op::str(\$this->{$param->name}) . ";
            $text = '';
        }
        $code .= self::phpString(' }');
        $this->w->line('public function ToString(): string');
        $this->w->line('{');
        $this->w->line("    return $code;");
        $this->w->line('}');
    }

    // ------------------------------------------------------------------
    // Interfaces and enums
    // ------------------------------------------------------------------

    private function emitInterface(TypeDecl $decl, TypeSymbol $symbol): void
    {
        $header = 'interface ' . Names::shortName($symbol->fqn);
        if ($symbol->interfaces !== []) {
            $header .= ' extends ' . implode(', ', array_map(fn (string $i): string => $this->classRef($i), $symbol->interfaces));
        }
        $this->emitTemplateDoc($decl->typeParams);
        $this->w->line($header);
        $this->w->line('{');
        $this->w->indent();

        foreach ($decl->members as $i => $member) {
            if ($i > 0) {
                $this->w->blank();
            }
            $this->w->sourceLine = $member->line;
            if ($member instanceof MethodDecl) {
                if ($member->kind !== 'method') {
                    throw $this->error('SP0526', 'Interfaces cannot contain constructors', $member);
                }
                $this->emitMethod($member, true);
            } elseif ($member instanceof PropertyDecl) {
                if ($member->exprBody !== null || $member->init !== null) {
                    throw $this->error('SP8701', 'Interface properties cannot have bodies or initializers', $member);
                }
                $type = $symbol->members[$member->name]->type;
                $hooks = '';
                foreach ($member->accessors as $accessor) {
                    $hooks .= ' ' . ($accessor->kind === 'get' ? 'get' : 'set') . ';';
                }
                $this->w->line('public ' . $this->typeDecl($type) . " \${$member->name} {{$hooks} }");
            }
        }

        $this->w->outdent();
        $this->w->line('}');
    }

    private function emitEnum(TypeDecl $decl, TypeSymbol $symbol): void
    {
        $this->w->line('enum ' . Names::shortName($symbol->fqn) . ': int');
        $this->w->line('{');
        $this->w->indent();
        $next = 0;
        foreach ($decl->enumMembers as $member) {
            $this->w->sourceLine = $member->line;
            if ($member->value !== null) {
                $value = $this->evaluateConstant($member->value);
                if (!is_int($value)) {
                    throw $this->error('SP0266', "Enum values must be integer constants ('{$member->name}')", $member->value);
                }
                $next = $value;
            }
            $this->w->line("case {$member->name} = $next;");
            $next++;
        }
        $this->w->outdent();
        $this->w->line('}');
    }

    // ------------------------------------------------------------------
    // Top-level statements
    // ------------------------------------------------------------------

    private function emitTopLevelProgram(CompilationUnit $unit): void
    {
        $symbol = $this->declarator->symbolOf($unit);
        $this->class = $symbol;
        $returnsValue = false;
        foreach ($unit->topLevelStatements as $statement) {
            if (NodeFinder::contains($statement, static fn (Node $n): bool => $n instanceof ReturnStmt && $n->value !== null)) {
                $returnsValue = true;
            }
        }
        $returnType = $returnsValue ? Type::int() : Type::void();
        $symbol->members['Main']->type = $returnType;

        $this->w->sourceLine = $unit->topLevelStatements[0]->line;
        $this->w->line('final class ' . Declarator::TOP_LEVEL_CLASS);
        $this->w->line('{');
        $this->w->indent();
        $this->w->line('public static function Main(array $args): ' . $returnType->kind);
        $this->w->line('{');
        $this->w->indent();
        $this->inFunction(new FunctionContext(true, $returnType), function () use ($unit): void {
            $this->inScope(function () use ($unit): void {
                $this->scope->declare('args', Type::array(Type::string()));
                $this->statements($unit->topLevelStatements);
            });
        });
        $this->w->outdent();
        $this->w->line('}');
        $this->w->outdent();
        $this->w->line('}');
        $this->class = null;
    }

    // ------------------------------------------------------------------
    // Constant expressions
    // ------------------------------------------------------------------

    /**
     * Code for a value used where PHP requires a constant expression
     * (property defaults, class constants, parameter defaults).
     */
    private function constantValue(Expr $expr, Type $expected): string
    {
        $value = $this->evaluateConstant($expr);
        if ($value !== self::notConstant()) {
            if ($expected->isFloat() && is_int($value)) {
                $value = (float) $value;
            }

            return $this->literalCode($value);
        }

        $outer = $this->fn;
        $this->fn = null;
        try {
            return $this->expr($expr, $expected)->code;
        } finally {
            $this->fn = $outer;
        }
    }

    private function isConstantExpression(Expr $expr): bool
    {
        if ($this->evaluateConstant($expr) !== self::notConstant()) {
            return true;
        }

        return match (true) {
            $expr instanceof TypeOfExpr, $expr instanceof NameOfExpr, $expr instanceof DefaultExpr => true,
            $expr instanceof UnaryExpr => !in_array($expr->operator, ['++', '--'], true) && $this->isConstantExpression($expr->operand),
            $expr instanceof BinaryExpr => !in_array($expr->operator, ['/', '%', '??'], true)
                && $this->isConstantExpression($expr->left) && $this->isConstantExpression($expr->right),
            $expr instanceof ConditionalExpr => $this->isConstantExpression($expr->condition)
                && $this->isConstantExpression($expr->then) && $this->isConstantExpression($expr->else),
            $expr instanceof ArrayCreationExpr => $expr->size === null && $expr->elements !== null
                && array_reduce($expr->elements, fn (bool $c, Expr $e): bool => $c && $this->isConstantExpression($e), true),
            $expr instanceof NameExpr, $expr instanceof MemberAccessExpr => $this->isConstantMember($expr),
            default => false,
        };
    }

    private function isConstantMember(NameExpr|MemberAccessExpr $expr): bool
    {
        try {
            $outer = $this->fn;
            $this->fn = null;
            $result = $expr instanceof NameExpr ? $this->name($expr, 'value') : $this->memberAccess($expr, 'value');
        } catch (\SharpPhp\Compiler\CompileError) {
            return false;
        } finally {
            $this->fn = $outer;
        }

        return $result->kind === 'value' && preg_match('/^[\\\\\w]+::[A-Za-z_]\w*$/', $result->code) === 1;
    }

    private static function notConstant(): object
    {
        static $marker = null;

        return $marker ??= new \stdClass();
    }

    /** Folds literal arithmetic; returns the notConstant() marker otherwise. */
    private function evaluateConstant(Expr $expr): mixed
    {
        $not = self::notConstant();

        if ($expr instanceof LiteralExpr) {
            return $expr->value;
        }
        if ($expr instanceof UnaryExpr && !$expr->postfix) {
            $v = $this->evaluateConstant($expr->operand);
            if ($v === $not) {
                return $not;
            }

            return match ($expr->operator) {
                '-' => is_int($v) || is_float($v) ? -$v : $not,
                '+' => is_int($v) || is_float($v) ? $v : $not,
                '!' => is_bool($v) ? !$v : $not,
                '~' => is_int($v) ? ~$v : $not,
                default => $not,
            };
        }
        if ($expr instanceof BinaryExpr) {
            $l = $this->evaluateConstant($expr->left);
            $r = $this->evaluateConstant($expr->right);
            if ($l === $not || $r === $not) {
                return $not;
            }
            $numeric = (is_int($l) || is_float($l)) && (is_int($r) || is_float($r));
            $ints = is_int($l) && is_int($r);

            return match ($expr->operator) {
                '+' => $numeric ? $l + $r : (is_string($l) || is_string($r) ? self::constString($l) . self::constString($r) : $not),
                '-' => $numeric ? $l - $r : $not,
                '*' => $numeric ? $l * $r : $not,
                '/' => $numeric && $r != 0 ? ($ints ? intdiv($l, $r) : $l / $r) : $not,
                '%' => $ints && $r !== 0 ? $l % $r : $not,
                '<<' => $ints ? $l << $r : $not,
                '>>' => $ints ? $l >> $r : $not,
                '&' => $ints ? $l & $r : $not,
                '|' => $ints ? $l | $r : $not,
                '^' => $ints ? $l ^ $r : $not,
                default => $not,
            };
        }

        return $not;
    }

    private static function constString(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'True' : 'False',
            $value === null => '',
            default => (string) $value,
        };
    }

    private function literalCode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::phpFloat($value),
            default => self::phpString((string) $value),
        };
    }

    /**
     * @template R
     * @param callable(): R $callback
     * @return R
     */
    private function inFunction(FunctionContext $context, callable $callback): mixed
    {
        $outer = $this->fn;
        $this->fn = $context;
        try {
            return $callback();
        } finally {
            $this->fn = $outer;
        }
    }
}
