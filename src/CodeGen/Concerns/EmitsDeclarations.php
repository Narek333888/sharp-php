<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\CodeGen\FunctionContext;
use SharpPhp\Compiler\Declarator;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\Accessor;
use SharpPhp\Syntax\Argument;
use SharpPhp\Syntax\ArrayCreationExpr;
use SharpPhp\Syntax\AssignExpr;
use SharpPhp\Syntax\AwaitExpr;
use SharpPhp\Syntax\BinaryExpr;
use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\ConditionalExpr;
use SharpPhp\Syntax\DefaultExpr;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\FieldDecl;
use SharpPhp\Syntax\LambdaExpr;
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

    /** @param list<string> $typeParams */
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
        $implements = array_map(fn (string $i): string => $this->classRef($i), $symbol->interfaces);
        if ($this->recordSerializesItself($symbol)) {
            $implements[] = '\\JsonSerializable';
        }
        if ($implements !== []) {
            $header .= ' implements ' . implode(', ', $implements);
        }

        $this->emitTemplateDoc($decl->typeParams);
        $this->w->line($header);
        $this->w->line('{');
        $this->w->indent();

        $consts = $fields = $properties = $methods = [];
        $constructor = null;
        $staticConstructor = null;
        $finalizer = null;
        foreach ($decl->members as $member) {
            match (true) {
                $member instanceof FieldDecl => $member->isConst ? $consts[] = $member : $fields[] = $member,
                $member instanceof PropertyDecl => $properties[] = $member,
                $member instanceof MethodDecl && $member->kind === 'constructor' => $constructor = $member,
                $member instanceof MethodDecl && $member->kind === 'static_constructor' => $staticConstructor = $member,
                $member instanceof MethodDecl && $member->kind === 'finalizer' => $finalizer = $member,
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
                $this->tolerating(function () use ($field, &$instanceInits, &$staticInits): void {
                    $this->emitField($field, $instanceInits, $staticInits);
                });
            }
        }

        foreach ($properties as $property) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->tolerating(function () use ($property, &$instanceInits, &$staticInits): void {
                $this->emitProperty($property, $instanceInits, $staticInits);
            });
        }

        if ($constructor !== null || $instanceInits !== [] || $decl->recordParams !== []) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->tolerating(fn () => $this->emitConstructor($constructor, $decl, $symbol, $instanceInits));
        }

        foreach ($methods as $method) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->tolerating(fn () => $this->emitMethod($method, false));
        }

        if ($finalizer !== null) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->tolerating(fn () => $this->emitFinalizer($finalizer, $symbol));
        }

        if ($symbol->isRecord && !isset($symbol->members['ToString'])) {
            if ($sections++ > 0) {
                $this->w->blank();
            }
            $this->emitRecordToString($decl);
        }
        if ($symbol->isRecord && !isset($symbol->members['Equals'])) {
            $this->w->blank();
            $this->emitObjectMember('Equals', 'return $other instanceof self && $other::class === static::class && $this == $other;');
        }
        if ($this->recordSerializesItself($symbol)) {
            // Records are data: as JSON (json_encode, a Laravel controller's result) they are their properties.
            $this->w->blank();
            $this->w->line('public function jsonSerialize(): \\stdClass');
            $this->w->line('{');
            $this->w->line('    return ' . $this->runtime('Json') . '::properties($this);');
            $this->w->line('}');
        }
        foreach ($this->objectMembersRequiredByInterfaces($symbol) as $name) {
            $this->w->blank();
            $this->emitObjectMember($name, match ($name) {
                'ToString' => 'return ' . $this->runtime('TypeName') . '::ofClass(static::class);',
                'Equals' => 'return $this === $other;',
                default => 'return \\spl_object_id($this);',
            });
        }

        if (isset($symbol->members['ToString']) || $symbol->isRecord) {
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
        // A C# readonly array field still has assignable elements, which PHP's readonly forbids;
        // the compiler already rejects assignments to the field itself.
        if ($readonly && $this->resolveType($field->type)->kind === 'array') {
            $readonly = false;
        }

        foreach ($field->declarators as $declarator) {
            $this->w->sourceLine = $declarator->line;
            $member = $this->currentClass()->members[$declarator->name];
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

            $inherited = $this->inheritedPhpProperty($declarator->name);
            $fieldReadonly = $readonly && ($inherited === null || $inherited->isReadOnly());
            [$phpType, $default] = $this->storageFor($type, $declarator->init, $fieldReadonly, $static, '$this->' . $declarator->name, 'self::$' . $declarator->name, $instanceInits, $staticInits, $declarator->line);
            if ($inherited !== null) {
                // A field that redeclares a PHP base class's property (Laravel's Model::$fillable,
                // Command::$signature) must keep its PHP type: none for Laravel's properties.
                $this->checkRedeclaredProperty($inherited, $visibility, $static, $declarator);
                $phpType = $inherited->hasType() ? self::phpTypeOf($inherited->getType()) : '';
                $fieldReadonly = $fieldReadonly && $phpType !== '';
            }
            $line = $visibility . ($static ? ' static' : '') . ($fieldReadonly ? ' readonly' : '') . ($phpType !== '' ? " $phpType" : '') . " \${$declarator->name}";
            $this->w->line($line . ($default !== null ? " = $default" : '') . ';');
        }
    }

    /**
     * The non-private property of the nearest PHP base class that a field named $name redeclares
     * (Laravel's Model::$fillable, Command::$signature), or null.
     */
    private function inheritedPhpProperty(string $name): ?\ReflectionProperty
    {
        $base = $this->currentClass()->baseClass;
        while ($base !== null) {
            $symbol = $this->symbols->find($base);
            if ($symbol === null) {
                return null;
            }
            if ($symbol->external) {
                if (!class_exists($symbol->fqn)) {
                    return null;
                }
                $class = new \ReflectionClass($symbol->fqn);
                if (!$class->hasProperty($name)) {
                    return null;
                }
                $property = $class->getProperty($name);

                return $property->isPrivate() ? null : $property;
            }
            $base = $symbol->baseClass;
        }

        return null;
    }

    /** PHP's rules for redeclaring an inherited property: the same static-ness, no stricter visibility. */
    private function checkRedeclaredProperty(\ReflectionProperty $inherited, string $visibility, bool $static, Node $at): void
    {
        $owner = \SharpPhp\Runtime\TypeName::ofClass($inherited->getDeclaringClass()->getName()) . '.' . $inherited->getName();
        $theirs = $inherited->isPublic() ? 'public' : 'protected';
        if ($inherited->isStatic() !== $static) {
            throw $this->error('SP0108', "'{$this->currentClass()->csharpName()}.{$inherited->getName()}' must be " . ($inherited->isStatic() ? 'static' : 'an instance field') . " like the inherited member '$owner'", $at);
        }
        if ($visibility === 'private' || ($visibility === 'protected' && $theirs === 'public')) {
            throw $this->error('SP0507', "'{$this->currentClass()->csharpName()}.{$inherited->getName()}': cannot change access modifiers of the inherited '$theirs' member '$owner'", $at);
        }
    }

    /** A PHP type declaration as written in code: "?string", "array", "\\Foo\\Bar", "int|string". */
    private static function phpTypeOf(\ReflectionType $type, bool $inUnion = false): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            $code = $type->isBuiltin() || in_array(strtolower($name), ['self', 'static', 'parent'], true) ? $name : '\\' . $name;

            return !$inUnion && $type->allowsNull() && !in_array($name, ['mixed', 'null'], true) ? '?' . $code : $code;
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(
                static fn (\ReflectionType $t): string => $t instanceof \ReflectionIntersectionType ? '(' . self::phpTypeOf($t, true) . ')' : self::phpTypeOf($t, true),
                $type->getTypes(),
            ));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(static fn (\ReflectionType $t): string => self::phpTypeOf($t, true), $type->getTypes()));
        }

        return '';
    }

    /**
     * Decides the PHP type and default value of a field or auto-property and queues
     * non-constant initializers for the constructor / static initializer.
     *
     * @return array{0: string, 1: ?string}
     *
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $staticInits
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

    /**
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $staticInits
     */
    private function emitProperty(PropertyDecl $property, array &$instanceInits, array &$staticInits): void
    {
        $this->w->sourceLine = $property->line;
        $member = $this->currentClass()->members[$property->name];
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

        if ($property->init !== null) {
            throw $this->error('SP8050', "Only auto-implemented properties can have initializers ('$name')", $property);
        }
        if ($static) {
            $this->emitStaticAccessors($property, $member, $visibility, $setVisibility);

            return;
        }

        $this->w->line("$visibility$asymmetric $phpType \$$name {");
        $this->w->indent();

        foreach ($this->accessorsOf($property) as $accessor) {
            $this->w->sourceLine = $accessor->line;
            $this->emitAccessor($accessor, $type, $name, static: false);
        }

        $this->w->outdent();
        $this->w->line('}');
    }

    /**
     * A property's accessors; "=> expr" is a get accessor.
     *
     * @return list<Accessor>
     */
    private function accessorsOf(PropertyDecl $property): array
    {
        if ($property->exprBody !== null) {
            return [(new Accessor('get', [], null, $property->exprBody))->at($property->exprBody)];
        }
        foreach ($property->accessors as $accessor) {
            if ($accessor->body === null && $accessor->exprBody === null) {
                throw $this->error('SP0202', "Mixing auto-implemented and custom accessors is not supported ('$property->name')", $accessor);
            }
        }

        return $property->accessors;
    }

    /**
     * A get/set/init accessor: a PHP property hook for an instance property, the body of the
     * get_X()/set_X() method for a static one.
     */
    private function emitAccessor(Accessor $accessor, Type $type, string $property, bool $static): void
    {
        $isGet = $accessor->kind === 'get';
        $context = new FunctionContext($static, $isGet ? $type : Type::void(), $this->class->typeParamTypes ?? []);
        self::analyzeCaptures($context, [$accessor]);
        $this->inFunction($context, function () use ($accessor, $isGet, $type, $property, $static): void {
            $this->inScope(function () use ($accessor, $isGet, $type, $property, $static): void {
                if (!$isGet) {
                    $this->scope->declare('value', $type);
                }
                if ($accessor->exprBody !== null) {
                    $code = $isGet ? $this->convert($accessor->exprBody, $type)->code : $this->effect($accessor->exprBody);
                    match (true) {
                        $static => $this->w->line($isGet ? "return $code;" : "$code;"),
                        $isGet => $this->w->line("get => $code;"),
                        default => array_map($this->w->line(...), ['set {', "    $code;", '}']),
                    };

                    return;
                }
                if (!$static) {
                    $this->w->line($isGet ? 'get {' : 'set {');
                    $this->w->indent();
                }
                $this->statements($accessor->body->statements ?? []);
                if ($isGet && $accessor->body !== null) {
                    $this->requireReturnOnAllPaths($accessor->body, "'{$this->class?->csharpName()}.$property.get'", $accessor);
                }
                if (!$static) {
                    $this->w->outdent();
                    $this->w->line('}');
                }
            });
        });
    }

    /**
     * static int Count => _items.Count;  =>  public static function get_Count(): int { return ...; }
     * A setter becomes set_Count(int $value): void.
     */
    private function asymmetricVisibility(string $visibility, string $setVisibility): string
    {
        $rank = ['public' => 3, 'protected' => 2, 'private' => 1];

        return $rank[$setVisibility] < $rank[$visibility] ? " $setVisibility(set)" : '';
    }

    private function emitStaticAccessors(PropertyDecl $property, \SharpPhp\Semantics\MemberSymbol $member, string $visibility, string $setVisibility): void
    {
        $phpType = (string) $this->typeDecl($member->type);
        foreach ($this->accessorsOf($property) as $i => $accessor) {
            if ($i > 0) {
                $this->w->blank();
            }
            $this->w->sourceLine = $accessor->line;
            $this->w->line($accessor->kind === 'get'
                ? "$visibility static function get_{$property->name}(): $phpType"
                : "$setVisibility static function set_{$property->name}($phpType \$value): void");
            $this->w->line('{');
            $this->w->indent();
            $this->emitAccessor($accessor, $member->type, $property->name, static: true);
            $this->w->outdent();
            $this->w->line('}');
        }
    }

    /**
     * @param list<array{0: string, 1: Expr, 2: Type, 3: int}> $instanceInits
     */
    private function emitConstructor(?MethodDecl $constructor, TypeDecl $decl, TypeSymbol $symbol, array $instanceInits): void
    {
        $this->w->sourceLine = $constructor->line ?? $decl->line;
        $context = new FunctionContext(false, Type::void(), $symbol->typeParamTypes, isConstructor: true);
        self::analyzeCaptures($context, [$constructor]);

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
                    $this->w->line("$visibility function __construct(" . $this->parameters($constructor->params ?? []) . ')');
                    $this->w->line('{');
                }

                $this->w->indent();
                $this->emitParamConversions();
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

        $this->w->sourceLine = $constructor->line ?? $decl->line;
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
            [$args] = $this->arguments($constructor->initializerArgs, $baseParams ?? [], runtime: $this->symbols->find((string) $symbol->baseClass)->external ?? false);
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
        $base = $this->symbols->find((string) $symbol->baseClass);
        $name = Names::csharpName((string) $symbol->baseClass);

        return $this->signature($name . '.' . ($base?->shortName() ?? $name), $params);
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
        $member = $this->currentClass()->members[$method->name];
        $modifiers = $method->modifiers;
        $static = in_array('static', $modifiers, true);
        $abstract = in_array('abstract', $modifiers, true);
        $typeParams = $this->declarator->typeParamsOf($method, $this->ctx, $this->currentClass()->typeParamTypes);
        $isGenerator = $method->body !== null && NodeFinder::contains($method->body, static fn (Node $n): bool => $n instanceof YieldStmt);

        if ($inInterface && ($method->body !== null || $method->exprBody !== null)) {
            throw $this->error('SP8701', 'Default interface methods are not supported', $method);
        }
        if (!$inInterface && !$abstract && $method->body === null && $method->exprBody === null) {
            throw $this->error('SP0501', "'{$method->name}' must declare a body because it is not marked abstract", $method);
        }

        $this->emitTemplateDoc($method->typeParams);
        if (in_array('override', $modifiers, true)) {
            $base = $this->currentClass()->baseClass !== null ? $this->symbols->find($this->currentClass()->baseClass) : null;
            $overridden = $base !== null ? $this->findMethod($base, $method->name) : null;
            if ($overridden !== null) {
                $this->w->line('#[\\Override]');
            } elseif (!in_array($method->name, ['ToString', 'Equals', 'GetHashCode'], true)) {
                // ToString/Equals/GetHashCode override System.Object, which has no PHP counterpart.
                throw $this->error('SP0115', "'{$this->currentClass()->csharpName()}.{$method->name}()': no suitable method found to override", $method);
            }
        }

        $async = in_array('async', $modifiers, true);
        $bodyType = $member->type;
        if ($async) {
            $bodyType = $this->asyncResultType($member->type)
                ?? throw $this->error('SP1983', "The return type of an async method must be void, Task or Task<T>", $method);
            foreach ($method->params as $param) {
                if (in_array($param->modifier, ['ref', 'out', 'in'], true)) {
                    throw $this->error('SP1988', 'Async methods cannot have ref, in or out parameters', $param);
                }
            }
            if ($isGenerator) {
                throw $this->error('SP1624', "The body of '{$this->currentClass()->csharpName()}.{$method->name}' cannot be an iterator block because 'Task' is not an iterator interface type", $method);
            }
            if ($abstract || $inInterface) {
                throw $this->error('SP1994', "The 'async' modifier can only be used in methods that have a body", $method);
            }
        }

        $context = new FunctionContext($static, $bodyType, $typeParams);
        $context->isAsync = $async;
        $context->localFunction = $method->isLocalFunction ? $method : null;
        self::analyzeCaptures($context, [$method]);
        $this->inFunction($context, function () use ($method, $member, $modifiers, $static, $abstract, $inInterface, $isGenerator, $async, $bodyType): void {
            $this->inScope(function () use ($method, $member, $modifiers, $static, $abstract, $inInterface, $isGenerator, $async, $bodyType): void {
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

                $this->w->line($signature);
                $this->w->line('{');
                $this->w->indent();
                $this->emitParamConversions();
                if ($async) {
                    $this->asyncBody($member->type, $static, array_map(static fn ($p): string => $p->name, $method->params), fn () => $this->methodBody($method, $bodyType));
                } else {
                    $this->methodBody($method, $member->type);
                }
                $this->requireOutParamsAssigned($method);
                if ($method->body !== null && $bodyType->kind !== 'void') {
                    $params = ParameterSymbol::describeTypes($member->params);
                    $this->requireReturnOnAllPaths($method->body, "'{$this->class?->csharpName()}.{$method->name}($params)'", $method);
                }
                $this->w->outdent();
                $this->w->line('}');
            });
        });
    }

    /** Before the method returns (or at its reachable end), every out parameter must be assigned. */
    private function requireOutParamsAssigned(Node $at): void
    {
        if (!$this->flow->isReachable() || $this->fn === null) {
            return;
        }
        foreach ($this->currentFunction()->outParams as $name) {
            if ($this->flow->isUnassigned($this->scope, $name)) {
                throw $this->error('SP0177', "The out parameter '$name' must be assigned to before control leaves the current method", $at);
            }
        }
    }

    /** After a non-void body: its end must be unreachable (iterators excepted), or C# reports CS0161. */
    private function requireReturnOnAllPaths(Block $body, string $what, Node $at): void
    {
        $isIterator = NodeFinder::contains($body, static fn (Node $n): bool => $n instanceof YieldStmt);
        if ($this->flow->isReachable() && !$isIterator) {
            throw $this->error('SP0161', "$what: not all code paths return a value", $at);
        }
    }

    /**
     * ~Name() as __destruct: PHP runs it as soon as the object is freed (when its last reference goes,
     * or when the cycle collector frees it), unless GC.SuppressFinalize(obj) was called. Like C#, the
     * base class's finalizer runs after it, even when it throws.
     */
    private function emitFinalizer(MethodDecl $finalizer, TypeSymbol $symbol): void
    {
        $this->w->sourceLine = $finalizer->line;
        $this->w->line('public function __destruct()');
        $this->w->line('{');
        $this->w->indent();
        $this->w->line('if (' . $this->runtime('System\\GC') . '::isFinalizerSuppressed($this)) {');
        $this->w->line('    return;');
        $this->w->line('}');
        $callsBase = $this->baseHasFinalizer($symbol);
        if ($callsBase) {
            $this->w->line('try {');
            $this->w->indent();
        }
        $this->inFunction(new FunctionContext(false, Type::void(), $symbol->typeParamTypes), function () use ($finalizer): void {
            $this->inScope(fn () => $this->methodBody($finalizer, Type::void()));
        });
        if ($callsBase) {
            $this->w->outdent();
            $this->w->line('} finally {');
            $this->w->line('    parent::__destruct();');
            $this->w->line('}');
        }
        $this->w->outdent();
        $this->w->line('}');
    }

    private function baseHasFinalizer(TypeSymbol $symbol): bool
    {
        for ($base = $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null; $base !== null; $base = $base->baseClass !== null ? $this->symbols->find($base->baseClass) : null) {
            if ($base->hasFinalizer || ($base->external && method_exists($base->fqn, '__destruct'))) {
                return true;
            }
        }

        return false;
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

    /**
     * The T of an async method's Task<T> (void for Task and for async void), or null when the type is
     * not one an async method can return.
     */
    private function asyncResultType(Type $declared): ?Type
    {
        if ($declared->kind === 'void') {
            return Type::void();
        }
        if ($declared->kind === 'class' && strcasecmp((string) $declared->class, self::TASK_CLASS) === 0) {
            return $declared->args[0] ?? Type::void();
        }

        return null;
    }

    /**
     * The body of an async method, run as a Task on the event loop (until its first await it runs
     * right away, as in C#):
     *
     *   return Task::async(function () use ($id) { ... });
     *
     * @param list<string> $captures the method's parameters
     * @param callable(): void $emitBody
     */
    private function asyncBody(Type $declared, bool $static, array $captures, callable $emitBody): void
    {
        $task = $this->classRef(self::TASK_CLASS);
        $use = $captures === [] ? '' : ' use (' . implode(', ', array_map(static fn (string $n): string => '$' . $n, $captures)) . ')';
        $this->w->line(($declared->kind === 'void' ? '' : 'return ') . "$task::async(" . ($static ? 'static ' : '') . "function ()$use {");
        $this->w->indent();
        $emitBody();
        $this->w->outdent();
        $this->w->line('});');
    }

    /** The base class or interface method that $name overrides or implements, if any. */
    private function inheritedMethod(string $name): ?\SharpPhp\Semantics\MemberSymbol
    {
        $candidates = [...($this->currentClass()->baseClass !== null ? [$this->currentClass()->baseClass] : []), ...$this->currentClass()->interfaces];
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
        $this->paramConversions = [];
        foreach ($params as $i => $param) {
            $type = $this->resolveType($param->type ?? throw new \LogicException('Method parameters are typed'));
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
            // PHP code may pass a plain array where a List, Dictionary or HashSet is expected.
            if ($prefix === '' && $this->conversions->collectionName($type, exact: true) !== null) {
                $decl = $this->typeDecl($type->withNullable(false)) . '|array' . ($type->nullable ? '|null' : '');
                $conversion = $this->convertedAtPhpBoundary(Emitted::value('$' . $param->name, Type::phpArray()), $type->withNullable(false));
                $this->paramConversions[] = "if (\\is_array(\${$param->name})) {\n    \${$param->name} = {$conversion->code};\n}";
            }
            $parts[] = ($decl !== null ? $decl . ' ' : '') . $prefix . '$' . $param->name . $default;
            $this->declareLocal($param->name, $localType, $param, assigned: $param->modifier !== 'out');
            if ($param->modifier === 'out') {
                $this->currentFunction()->outParams[] = $param->name;
            }
        }

        return implode(', ', $parts);
    }

    private function emitParamConversions(): void
    {
        foreach ($this->paramConversions as $statement) {
            foreach (explode("\n", $statement) as $line) {
                $this->w->line($line);
            }
        }
        $this->paramConversions = [];
    }

    /**
     * System.Object members (ToString, Equals, GetHashCode) that an interface of a non-record
     * class redeclares but the class does not: C# objects always have them, PHP classes must
     * define them to implement the interface.
     *
     * @return list<string>
     */
    private function objectMembersRequiredByInterfaces(TypeSymbol $symbol): array
    {
        if ($symbol->isRecord) {
            return [];
        }
        $required = [];
        foreach ($this->symbols->interfacesOf($symbol) as $interface) {
            foreach (['ToString', 'Equals', 'GetHashCode'] as $name) {
                if (isset($interface->members[$name]) && $this->symbols->findMember($symbol, $name)?->owner->isInterface() !== false) {
                    $required[$name] = true;
                }
            }
        }

        return array_keys($required);
    }

    /** A System.Object member with the given body (signatures as in Op::str/Op::equals). */
    /**
     * A record implements \\JsonSerializable, unless a record it derives from already does or it
     * declares jsonSerialize itself.
     */
    private function recordSerializesItself(TypeSymbol $symbol): bool
    {
        if (!$symbol->isRecord || isset($symbol->members['jsonSerialize'])) {
            return false;
        }
        $base = $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null;

        return $base === null || !$base->isRecord || $base->external;
    }

    private function emitObjectMember(string $name, string $body): void
    {
        $this->w->line(match ($name) {
            'ToString' => 'public function ToString(): string',
            'Equals' => 'public function Equals(mixed $other): bool',
            default => 'public function GetHashCode(): int',
        });
        $this->w->line('{');
        $this->w->line('    ' . $body);
        $this->w->line('}');
    }

    private function emitRecordToString(TypeDecl $decl): void
    {
        $this->w->sourceLine = $decl->line;
        $op = $this->runtime('Op');

        // Like C#'s PrintMembers: public instance fields and properties, base record members first.
        $chain = [];
        for ($symbol = $this->class; $symbol !== null && !$symbol->external; $symbol = $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null) {
            array_unshift($chain, $symbol);
        }
        $names = [];
        foreach ($chain as $symbol) {
            foreach ($symbol->members as $member) {
                if ($member->isValue() && !$member->isStatic && $member->visibility === 'public' && !in_array($member->name, $names, true)) {
                    $names[] = $member->name;
                }
            }
        }
        if ($names === []) {
            $code = self::phpString($decl->name . ' { }');
        } else {
            $code = '';
            $text = $decl->name . ' { ';
            foreach ($names as $i => $name) {
                $text .= ($i > 0 ? ', ' : '') . $name . ' = ';
                $code .= self::phpString($text) . " . $op::str(\$this->$name) . ";
                $text = '';
            }
            $code .= self::phpString(' }');
        }
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
        // await in top-level statements makes Main async (Task / Task<int>).
        $async = false;
        foreach ($unit->topLevelStatements as $statement) {
            if (NodeFinder::contains($statement, static fn (Node $n): bool => $n instanceof AwaitExpr)) {
                $async = true;
            }
        }
        $mainType = $async ? Type::class(self::TASK_CLASS, $returnsValue ? [Type::int()] : []) : $returnType;
        $symbol->members['Main']->type = $mainType;

        $this->w->sourceLine = $unit->topLevelStatements[0]->line;
        $this->w->line('final class ' . Declarator::TOP_LEVEL_CLASS);
        $this->w->line('{');
        $this->w->indent();
        $this->w->line('public static function Main(array $args): ' . ($async ? $this->classRef(self::TASK_CLASS) : $returnType->kind));
        $this->w->line('{');
        $this->w->indent();
        $context = new FunctionContext(true, $returnType);
        $context->isAsync = $async;
        self::analyzeCaptures($context, $unit->topLevelStatements);
        $this->inFunction($context, function () use ($unit, $async, $mainType): void {
            $this->inScope(function () use ($unit, $async, $mainType): void {
                $this->scope->declare('args', Type::array(Type::string()));
                if ($async) {
                    $this->asyncBody($mainType, true, ['args'], fn () => $this->statements($unit->topLevelStatements));
                } else {
                    $this->statements($unit->topLevelStatements);
                }
            });
        });
        $this->w->outdent();
        $this->w->line('}');
        foreach ($unit->localFunctions as $function) {
            $this->w->blank();
            $this->tolerating(fn () => $this->emitMethod($function, false));
        }
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
        /** @var \stdClass|null $marker */
        static $marker = null;
        $marker ??= new \stdClass();

        return $marker;
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
            is_scalar($value) => (string) $value,
            default => throw new \LogicException('Not a constant value: ' . get_debug_type($value)),
        };
    }

    private function literalCode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::phpFloat($value),
            is_string($value) => self::phpString($value),
            default => throw new \LogicException('Not a constant value: ' . get_debug_type($value)),
        };
    }

    /**
     * Finds the variables a lambda must capture by reference: C# lambdas capture variables, not
     * values, so "() => count" sees a later "count = 10". PHP's fn () => captures by value, which
     * stays right for variables that are never reassigned (a per-iteration "var item = ...").
     *
     * @param list<Node|null> $roots
     */
    private static function analyzeCaptures(FunctionContext $context, array $roots): void
    {
        $reassigned = [];
        $inLambdas = [];
        foreach (NodeFinder::all($roots, static fn (Node $n): bool => $n instanceof AssignExpr || $n instanceof UnaryExpr
            || $n instanceof Argument || $n instanceof LambdaExpr) as $node) {
            $target = match (true) {
                $node instanceof AssignExpr => $node->target,
                $node instanceof UnaryExpr => in_array($node->operator, ['++', '--'], true) ? $node->operand : null,
                $node instanceof Argument => in_array($node->modifier, ['ref', 'out'], true) ? $node->value : null,
                default => null,
            };
            if ($target instanceof NameExpr) {
                $reassigned[$target->name] = true;
            }
            if ($node instanceof LambdaExpr) {
                foreach (NodeFinder::all([$node->body], static fn (Node $n): bool => $n instanceof NameExpr) as $name) {
                    \assert($name instanceof NameExpr);
                    $inLambdas[$name->name] = true;
                }
            }
        }
        $context->reassigned = $reassigned;
        $context->capturedByLambdas = $inLambdas;
    }

    /**
     * Runs $callback with $context as the current function. Methods, accessors and constructors
     * also start a fresh flow state; lambdas (which have a parent) see the enclosing one.
     *
     * @template R
     * @param callable(): R $callback
     * @return R
     */
    private function inFunction(FunctionContext $context, callable $callback): mixed
    {
        $outer = $this->fn;
        $this->fn = $context;
        try {
            return $context->parent === null ? $this->flow->inFunction($callback) : $callback();
        } finally {
            $this->fn = $outer;
        }
    }
}
