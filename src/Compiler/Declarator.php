<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\ResolutionContext;
use SharpPhp\Semantics\SymbolTable;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeResolver;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\FieldDecl;
use SharpPhp\Syntax\MethodDecl;
use SharpPhp\Syntax\NamespaceDecl;
use SharpPhp\Syntax\Node;
use SharpPhp\Syntax\PropertyDecl;
use SharpPhp\Syntax\TypeDecl;

/**
 * Builds symbols for all project types before any code is emitted, so that every file
 * can see every other file's classes (like a C# project compilation).
 */
final class Declarator
{
    public const TOP_LEVEL_CLASS = 'Program';

    /** @var array<int, TypeSymbol> spl_object_id(TypeDecl) => symbol */
    private array $symbolsByDecl = [];

    /** @var list<array{type: TypeSymbol, file: SourceFile, node: Node}> */
    private array $entryPoints = [];

    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly TypeResolver $resolver,
    ) {
    }

    /** Phase 1: register type names. */
    public function register(CompilationUnit $unit): void
    {
        foreach ($unit->namespaces as $ns) {
            $phpNs = Names::phpNamespace($ns->name);
            $this->symbols->addNamespace($phpNs);
            foreach ($ns->types as $decl) {
                $kind = match ($decl->kind) {
                    'interface' => 'interface',
                    'enum' => 'enum',
                    'delegate' => 'delegate',
                    default => 'class',
                };
                $fqn = ($phpNs === '' ? '' : $phpNs . '\\') . Names::mangle($decl->name);
                $symbol = new TypeSymbol($kind, $fqn, false);
                $symbol->isRecord = $decl->kind === 'record';
                $symbol->isStruct = $decl->kind === 'struct';
                $symbol->isAbstract = in_array('abstract', $decl->modifiers, true);
                $symbol->typeParams = $decl->typeParams;
                $symbol->variance = $decl->variance;
                $symbol->location = [$unit->file->path, $decl->line, $decl->column];
                if ($decl->kind === 'delegate') {
                    // Resolved where it is used (TypeResolver), maybe before phase 2 reaches it.
                    $symbol->delegateDecl = $decl;
                    $symbol->delegateContext = $this->contextFor($unit, $ns);
                }
                if ($this->symbols->declare($symbol) !== null) {
                    $where = $ns->name === '' ? 'the global namespace' : "namespace '{$ns->name}'";
                    throw $this->error('SP0101', "The $where already contains a definition for '{$decl->name}'", $unit->file, $decl);
                }
                $this->symbolsByDecl[spl_object_id($decl)] = $symbol;
            }
        }

        if ($unit->topLevelStatements !== []) {
            $symbol = new TypeSymbol('class', self::TOP_LEVEL_CLASS, false);
            if ($this->symbols->declare($symbol) !== null) {
                throw $this->error('SP8802', 'Only one compilation unit can have top-level statements', $unit->file, $unit->topLevelStatements[0]);
            }
            $symbol->addMember(new MemberSymbol('method', 'Main', true, Type::void(), $symbol, [
                new ParameterSymbol('args', Type::array(Type::string())),
            ]));
            $this->symbolsByDecl[spl_object_id($unit)] = $symbol;
            $this->entryPoints[] = ['type' => $symbol, 'file' => $unit->file, 'node' => $unit->topLevelStatements[0]];
        }
    }

    /** Phase 2: resolve base types and members. */
    public function define(CompilationUnit $unit): void
    {
        foreach ($unit->namespaces as $ns) {
            $ctx = $this->contextFor($unit, $ns);
            foreach ($ns->types as $decl) {
                if ($decl->kind === 'delegate') {
                    // Check the signature's types now (TypeResolver resolves it where it is used).
                    $this->resolver->resolve((new \SharpPhp\Syntax\TypeRef($decl->name, array_map(static fn (string $t): \SharpPhp\Syntax\TypeRef => new \SharpPhp\Syntax\TypeRef($t, [], false), $decl->typeParams), false))->at($decl), $ctx, array_fill_keys($decl->typeParams, Type::mixed()));
                    continue;
                }
                $this->defineType($decl, $this->symbolOf($decl), $ctx);
                if ($decl->variance !== []) {
                    $this->checkVariance($decl, $this->symbolOf($decl), $ctx->file);
                }
            }
        }
        if ($unit->localFunctions !== [] && $unit->topLevelStatements !== []) {
            $this->defineTopLevelFunctions($unit);
        }
    }

    /** The local functions of top-level statements: private static methods of Program, next to Main. */
    private function defineTopLevelFunctions(CompilationUnit $unit): void
    {
        $symbol = $this->symbolOf($unit);
        $ctx = $this->contextFor($unit, null);
        $names = ['main' => true];
        foreach ($unit->localFunctions as $function) {
            $key = strtolower($function->name);
            if (isset($names[$key])) {
                throw $this->error('SP0128', "A local function named '{$function->name}' is already declared in the top-level statements (local functions are compiled to methods of Program, whose names are case-insensitive)", $unit->file, $function);
            }
            $names[$key] = true;
            $typeParams = $this->typeParamsOf($function, $ctx);
            $method = new MemberSymbol(
                'method',
                $function->name,
                true,
                $this->resolver->resolve($function->returnType ?? throw new \LogicException('Local functions have a return type'), $ctx, $typeParams),
                $symbol,
                $this->parameterSymbols($function, $ctx, $typeParams),
                $function->typeParams,
                'private',
            );
            $method->isLocalFunction = true;
            $this->add($symbol, $method, $unit->file, $function);
        }
    }

    /**
     * C#'s variance safety: in "interface IBox<out T>", T may only be produced (returned), never
     * consumed (a parameter); in "ISink<in T>" the other way round. A property with get and set, and
     * ref/out parameters, need invariant types.
     */
    private function checkVariance(TypeDecl $decl, TypeSymbol $symbol, SourceFile $file): void
    {
        $owner = $symbol->csharpName() . '<' . implode(', ', $symbol->typeParams) . '>';
        foreach ($decl->members as $node) {
            if (!($node instanceof MethodDecl && $node->kind === 'method') && !$node instanceof PropertyDecl) {
                continue;
            }
            $member = $symbol->members[$node->name] ?? null;
            if ($member === null) {
                continue;
            }
            if ($node instanceof MethodDecl) {
                $where = "$owner.{$member->name}(" . implode(', ', array_map(static fn (ParameterSymbol $p): string => $p->type->describe(), $member->params)) . ')';
                $checks = [[$member->type, 1]];
                foreach ($member->params as $param) {
                    $checks[] = [$param->type, in_array($param->modifier, ['ref', 'out'], true) ? 0 : -1];
                }
            } else {
                $kinds = array_map(static fn ($a): string => $a->kind, $node->accessors);
                $get = $node->accessors === [] || in_array('get', $kinds, true);
                $set = in_array('set', $kinds, true) || in_array('init', $kinds, true);
                $where = "$owner.{$member->name}";
                $checks = [[$member->type, $get && $set ? 0 : ($get ? 1 : -1)]];
            }
            foreach ($checks as [$type, $position]) {
                $violation = $this->varianceViolation($type, $position, $symbol);
                if ($violation !== null) {
                    [$name, $variance, $required] = $violation;
                    $validity = match ($required) {
                        1 => 'covariantly',
                        -1 => 'contravariantly',
                        default => 'invariantly',
                    };
                    $is = $variance === 'out' ? 'covariant' : 'contravariant';
                    throw $this->error('SP1961', "Invalid variance: The type parameter '$name' must be $validity valid on '$where'. '$name' is $is.", $file, $node);
                }
            }
        }
    }

    /**
     * A variant type parameter of $owner used where its variance does not allow it, with the position
     * it would need (1 covariant, -1 contravariant, 0 invariant).
     *
     * @return array{0: string, 1: 'in'|'out', 2: int}|null
     */
    private function varianceViolation(Type $type, int $position, TypeSymbol $owner): ?array
    {
        switch ($type->kind) {
            case 'typeparam':
                $variance = $owner->variance[(string) $type->param] ?? null;
                if (($variance === 'out' && $position !== 1) || ($variance === 'in' && $position !== -1)) {
                    return [(string) $type->param, $variance, $position];
                }

                return null;
            case 'array':
            case 'iterable':
                return $this->varianceViolation($type->element(), $position, $owner);
            case 'closure':
                $last = count($type->args) - 1;
                foreach ($type->args as $i => $arg) {
                    if ($i === $last && $arg->kind === 'void') {
                        continue;
                    }
                    $violation = $this->varianceViolation($arg, $i === $last ? $position : -$position, $owner);
                    if ($violation !== null) {
                        return $violation;
                    }
                }

                return null;
            case 'class':
                $generic = $this->symbols->find((string) $type->class);
                foreach ($type->args as $i => $arg) {
                    $variance = $generic !== null ? ($generic->variance[$generic->typeParams[$i] ?? ''] ?? null) : null;
                    $inner = match ($variance) {
                        'out' => $position,
                        'in' => -$position,
                        default => 0,
                    };
                    $violation = $this->varianceViolation($arg, $inner, $owner);
                    if ($violation !== null) {
                        return $violation;
                    }
                }

                return null;
            default:
                return null;
        }
    }







    public function contextFor(CompilationUnit $unit, ?NamespaceDecl $ns): ResolutionContext
    {
        $usings = [];
        $aliases = [];
        foreach ([...$unit->usings, ...($ns->usings ?? [])] as $using) {
            if ($using->alias !== null) {
                $aliases[$using->alias] = $using->name;
            } else {
                $usings[] = $using->name;
            }
        }

        return new ResolutionContext($unit->file, $ns->name ?? '', $usings, $aliases);
    }

    public function symbolOf(TypeDecl|CompilationUnit $decl): TypeSymbol
    {
        return $this->symbolsByDecl[spl_object_id($decl)];
    }

    /** @return list<array{type: TypeSymbol, file: SourceFile, node: Node}> */
    public function entryPoints(): array
    {
        return $this->entryPoints;
    }

    /**
     * Type parameters in scope inside $decl: the outer ones plus its own, with their class constraints.
     *
     * @param array<string, Type> $outer
     * @return array<string, Type>
     */
    public function typeParamsOf(TypeDecl|MethodDecl $decl, ResolutionContext $ctx, array $outer = []): array
    {
        $result = $outer;
        foreach ($decl->typeParams as $name) {
            $result[$name] = Type::typeParam($name);
        }
        foreach ($decl->typeParams as $name) {
            $constraint = null;
            foreach ($decl->constraints[$name] ?? [] as $ref) {
                $type = $this->resolver->resolve($ref, $ctx, $result);
                if ($type->isClass()) {
                    $constraint = $type->class;
                    break;
                }
            }
            $result[$name] = Type::typeParam($name, $constraint);
        }

        return $result;
    }

    private function defineType(TypeDecl $decl, TypeSymbol $symbol, ResolutionContext $ctx): void
    {
        $file = $ctx->file;
        $typeParams = $this->typeParamsOf($decl, $ctx);
        $symbol->typeParamTypes = $typeParams;

        if ($decl->kind !== 'enum') {
            foreach ($decl->baseTypes as $ref) {
                $type = $this->resolver->resolve($ref, $ctx, $typeParams);
                $base = $type->isClass() ? $this->symbols->find((string) $type->class) : null;
                if ($base === null) {
                    throw $this->error('SP0527', "Type '{$type->describe()}' in the base list is not a class or interface", $file, $ref);
                }
                $symbol->supertypeArgs[strtolower($base->fqn)] = $type->args;
                if ($base->isInterface()) {
                    $symbol->interfaces[] = $base->fqn;
                    continue;
                }
                if ($decl->kind === 'interface') {
                    throw $this->error('SP0527', "Type '{$base->csharpName()}' in interface list is not an interface", $file, $ref);
                }
                if ($base->isEnum()) {
                    throw $this->error('SP0509', "'{$decl->name}': cannot derive from enum type '{$base->csharpName()}'", $file, $ref);
                }
                if ($symbol->baseClass !== null) {
                    throw $this->error('SP1721', "Class '{$decl->name}' cannot have multiple base classes", $file, $ref);
                }
                if ($ref !== $decl->baseTypes[0]) {
                    throw $this->error('SP1722', "Base class '{$base->csharpName()}' must come before any interfaces", $file, $ref);
                }
                $symbol->baseClass = $base->fqn;
            }
        }

        $defaultVisibility = $decl->kind === 'interface' ? 'public' : 'private';
        $methodNames = [];

        foreach ($decl->recordParams as $param) {
            $type = $this->resolver->resolve($param->type ?? throw new \LogicException('Record parameters are typed'), $ctx, $typeParams);
            $property = new MemberSymbol('property', $param->name, false, $type, $symbol, visibility: 'public');
            $property->setter = 'init';
            $property->isAutoProperty = true;
            $this->add($symbol, $property, $file, $param);
            $symbol->hasConstructor = true;
            $symbol->constructorParams[] = new ParameterSymbol($param->name, $type, null, $param->default !== null);
        }

        foreach ($decl->enumMembers as $member) {
            $this->add($symbol, new MemberSymbol('case', $member->name, true, Type::class($symbol->fqn), $symbol), $file, $member);
        }

        foreach ($decl->members as $member) {
            if ($member instanceof FieldDecl) {
                $type = $this->resolver->resolve($member->type, $ctx, $typeParams);
                $static = $member->isConst || in_array('static', $member->modifiers, true);
                foreach ($member->declarators as $declarator) {
                    $field = new MemberSymbol(
                        $member->isConst ? 'const' : 'field',
                        $declarator->name,
                        $static,
                        $type,
                        $symbol,
                        visibility: self::visibility($member->modifiers, $defaultVisibility),
                    );
                    $field->isReadonly = in_array('readonly', $member->modifiers, true);
                    $field->isEvent = in_array('event', $member->modifiers, true);
                    if ($field->isEvent && $type->kind !== 'closure') {
                        throw $this->error('SP0066', "'{$decl->name}.{$declarator->name}': event must be of a delegate type", $file, $declarator);
                    }
                    $field->constantValue = $member->isConst ? $declarator->init : null;
                    // Laravel's Model::$casts, $hidden, ...: a field redeclaring a property of a PHP base
                    // class is read by PHP code, which takes a PHP array, not a Dictionary or List.
                    if (!$static && $this->phpBaseProperty($symbol, $declarator->name)) {
                        $this->storeAsPhpArray($field);
                    }
                    $this->add($symbol, $field, $file, $declarator);
                }
            } elseif ($member instanceof PropertyDecl) {
                $type = $this->resolver->resolve($member->type, $ctx, $typeParams);
                $property = new MemberSymbol(
                    'property',
                    $member->name,
                    in_array('static', $member->modifiers, true),
                    $type,
                    $symbol,
                    visibility: self::visibility($member->modifiers, $defaultVisibility),
                );
                $property->isAbstract = in_array('abstract', $member->modifiers, true);
                $property->isAutoProperty = $member->exprBody === null;
                foreach ($member->accessors as $accessor) {
                    if ($accessor->body !== null || $accessor->exprBody !== null) {
                        $property->isAutoProperty = false;
                    }
                    if ($accessor->kind !== 'get') {
                        $property->setter = $accessor->kind === 'init' ? 'init' : self::visibility($accessor->modifiers, $property->visibility);
                    }
                }
                $property->isStaticAccessor = $property->isStatic && !$property->isAutoProperty;
                $this->add($symbol, $property, $file, $member);
            } elseif ($member instanceof MethodDecl) {
                if ($member->kind === 'static_constructor') {
                    continue;
                }
                if ($member->kind === 'finalizer') {
                    if (in_array('static', $decl->modifiers, true)) {
                        throw $this->error('SP0711', 'Static classes cannot contain destructors', $file, $member);
                    }
                    if ($symbol->hasFinalizer) {
                        throw $this->error('SP0111', "Type '{$decl->name}' already defines a member called 'Finalize' with the same parameter types", $file, $member);
                    }
                    $symbol->hasFinalizer = true;
                    continue;
                }
                if ($member->kind === 'constructor') {
                    if ($symbol->hasConstructor) {
                        $what = $decl->recordParams !== [] ? 'a record with a primary constructor' : 'more than one constructor';
                        throw $this->error('SP0111', "Constructor overloading is not supported ('{$decl->name}' declares $what)", $file, $member);
                    }
                    $symbol->hasConstructor = true;
                    $symbol->constructorParams = $this->parameterSymbols($member, $ctx, $typeParams);
                    $symbol->constructorVisibility = self::visibility($member->modifiers);
                    continue;
                }
                $key = strtolower($member->name);
                if (isset($methodNames[$key]) || ($member->isLocalFunction && isset($symbol->members[$member->name]))) {
                    if ($member->isLocalFunction || ($methodNames[$key] ?? null) === 'local') {
                        throw $this->error('SP0128', "A local function is compiled to a private method of '{$decl->name}', which already has a member called '{$member->name}' (PHP method names are case-insensitive): rename one of them", $file, $member);
                    }
                    throw $this->error('SP0111', "Method overloading is not supported: '{$decl->name}' already defines a member called '{$member->name}' (PHP method names are case-insensitive)", $file, $member);
                }
                $methodNames[$key] = $member->isLocalFunction ? 'local' : true;

                $methodTypeParams = $this->typeParamsOf($member, $ctx, $typeParams);
                $params = $this->parameterSymbols($member, $ctx, $methodTypeParams);
                $isStatic = in_array('static', $member->modifiers, true);
                $method = new MemberSymbol(
                    'method',
                    $member->name,
                    $isStatic,
                    $this->resolver->resolve($member->returnType ?? throw new \LogicException('Methods have a return type'), $ctx, $methodTypeParams),
                    $symbol,
                    $params,
                    $member->typeParams,
                    self::visibility($member->modifiers, $defaultVisibility),
                );
                $method->isAbstract = in_array('abstract', $member->modifiers, true);
                $method->isOverride = in_array('override', $member->modifiers, true);
                $method->isVirtual = $method->isAbstract || $method->isOverride || in_array('virtual', $member->modifiers, true);
                $method->isLocalFunction = $member->isLocalFunction;
                // Laravel calls rules(), casts(), messages() ... of the classes derived from its own, and
                // takes PHP arrays from them: such a method returns its List/Dictionary as one.
                if (!$isStatic && $method->visibility !== 'private' && !$member->isLocalFunction && $this->hasPhpBaseClass($symbol)) {
                    $this->storeAsPhpArray($method, keepType: true);
                }
                $this->declareExtension($decl, $member, $method, $file);
                $method->extensionProperty = $member->extensionProperty;
                if ($member->extensionOf !== null) {
                    // A static member of an extension block (C# 14): called on the type it extends.
                    if ($decl->kind !== 'class' || !in_array('static', $decl->modifiers, true) || $decl->typeParams !== []) {
                        throw $this->error('SP1106', 'Extension members must be defined in a non-generic static class', $file, $member);
                    }
                    $method->staticExtensionOf = $this->resolver->resolve($member->extensionOf, $ctx, $methodTypeParams);
                    $this->symbols->addStaticExtension($method);
                }
                $this->add($symbol, $method, $file, $member);

                if ($member->name === 'Main' && $isStatic) {
                    $this->entryPoints[] = ['type' => $symbol, 'file' => $file, 'node' => $member];
                }
            }
        }
    }

    /**
     * A method whose first parameter is declared "this" is an extension method (see ExtensionMethods),
     * which C# allows only as a static method of a non-generic static class.
     */
    private function declareExtension(TypeDecl $decl, MethodDecl $method, MemberSymbol $symbol, SourceFile $file): void
    {
        foreach ($method->params as $i => $param) {
            if ($param->modifier !== 'this') {
                continue;
            }
            if ($i > 0) {
                throw $this->error('SP1100', "Method '{$method->name}' has a parameter modifier 'this' which is not on the first parameter", $file, $param);
            }
            if ($decl->kind !== 'class' || !in_array('static', $decl->modifiers, true) || $decl->typeParams !== []) {
                throw $this->error('SP1106', 'Extension method must be defined in a non-generic static class', $file, $method);
            }
            if (!$symbol->isStatic) {
                throw $this->error('SP1105', 'Extension method must be static', $file, $method);
            }
            $symbol->isExtension = true;
            $this->symbols->addExtension($symbol);
        }
    }

    /**
     * @param array<string, Type> $typeParams
     * @return list<ParameterSymbol>
     */
    private function parameterSymbols(MethodDecl $method, ResolutionContext $ctx, array $typeParams): array
    {
        $params = [];
        foreach ($method->params as $param) {
            $params[] = new ParameterSymbol(
                $param->name,
                $this->resolver->resolve($param->type ?? throw new \LogicException('Method parameters are typed'), $ctx, $typeParams),
                $param->modifier,
                $param->default !== null || $param->modifier === 'params',
                callerInfo: $param->callerInfo,
            );
        }

        return $params;
    }

    private function add(TypeSymbol $symbol, MemberSymbol $member, SourceFile $file, Node $at): void
    {
        if (isset($symbol->members[$member->name])) {
            throw $this->error('SP0102', "The type '{$symbol->csharpName()}' already contains a definition for '{$member->name}'", $file, $at);
        }
        $member->location = [$file->path, $at->line, $at->column];
        $symbol->addMember($member);
    }

    /**
     * A List, Dictionary or HashSet member becomes a PHP array (MemberSymbol::$phpArrayOf). A method keeps
     * its type for SharpPHP code, which gets the collection back from the array (see EmitsMembers).
     */
    private function storeAsPhpArray(MemberSymbol $member, bool $keepType = false): void
    {
        $type = $member->type;
        $collections = [
            Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\List_' => 0,
            Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\HashSet' => 0,
            Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\Dictionary' => 1,
        ];
        if (!$type->isClass() || !isset($collections[(string) $type->class])) {
            return;
        }
        $member->phpArrayOf = $type;
        if (!$keepType) {
            $member->type = Type::phpArray($type->args[$collections[(string) $type->class]] ?? null)->withNullable($type->nullable);
        }
    }

    /** The class derives from a PHP class (Laravel's Model, Controller, FormRequest), not from SharpPHP's own. */
    private function hasPhpBaseClass(TypeSymbol $symbol): bool
    {
        for ($base = $symbol->baseClass; $base !== null; $base = $this->symbols->find($base)?->baseClass) {
            $baseSymbol = $this->symbols->find($base);
            if ($baseSymbol === null) {
                return false;
            }
            if ($baseSymbol->external) {
                return !str_starts_with($baseSymbol->fqn, Names::RUNTIME_NAMESPACE . '\\');
            }
        }

        return false;
    }

    /** A non-private property of the nearest PHP base class has this name (Model::$casts). */
    private function phpBaseProperty(TypeSymbol $symbol, string $name): bool
    {
        if (!$this->hasPhpBaseClass($symbol)) {
            return false;
        }
        for ($base = $symbol->baseClass; $base !== null; $base = $this->symbols->find($base)?->baseClass) {
            $baseSymbol = $this->symbols->find($base);
            if ($baseSymbol !== null && $baseSymbol->external) {
                if (!class_exists($baseSymbol->fqn) || !property_exists($baseSymbol->fqn, $name)) {
                    return false;
                }

                return !(new \ReflectionProperty($baseSymbol->fqn, $name))->isPrivate();
            }
        }

        return false;
    }

    /** @param list<string> $modifiers */
    public static function visibility(array $modifiers, string $default = 'private'): string
    {
        // A SharpPHP project is one assembly: internal and protected internal mean public,
        // private protected means protected.
        $internal = in_array('internal', $modifiers, true) && !in_array('private', $modifiers, true);
        if (in_array('public', $modifiers, true) || $internal) {
            return 'public';
        }
        if (in_array('protected', $modifiers, true)) {
            return 'protected';
        }
        if (in_array('private', $modifiers, true)) {
            return 'private';
        }

        return $default;
    }

    private function error(string $code, string $message, SourceFile $file, Node $at): CompileError
    {
        return CompileError::at($code, $message, $file, $at->line, $at->column);
    }
}
