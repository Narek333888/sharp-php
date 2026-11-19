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

    /** Runtime interfaces that do not declare their method in PHP (see IComparable), with the method C# requires. */
    private const IMPLIED_INTERFACE_METHODS = [
        Names::RUNTIME_NAMESPACE . '\\System\\IComparable' => 'CompareTo(T)',
        Names::RUNTIME_NAMESPACE . '\\System\\IEquatable' => 'Equals(T)',
    ];

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
                    default => 'class',
                };
                $fqn = ($phpNs === '' ? '' : $phpNs . '\\') . Names::mangle($decl->name);
                $symbol = new TypeSymbol($kind, $fqn, false);
                $symbol->isRecord = $decl->kind === 'record';
                $symbol->isAbstract = in_array('abstract', $decl->modifiers, true);
                $symbol->typeParams = $decl->typeParams;
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
                $this->defineType($decl, $this->symbolOf($decl), $ctx);
            }
        }
    }

    /**
     * Phase 3: checks that need every type's members. PHP would only report these
     * as a fatal error when the class is loaded; C# rejects them at compile time.
     */
    public function verify(CompilationUnit $unit): void
    {
        foreach ($unit->namespaces as $ns) {
            foreach ($ns->types as $decl) {
                $symbol = $this->symbolOf($decl);
                if (!$symbol->isInterface() && !$symbol->isEnum()) {
                    $this->verifyImplementation($decl, $symbol, $unit->file);
                }
            }
        }
    }

    private function verifyImplementation(TypeDecl $decl, TypeSymbol $symbol, SourceFile $file): void
    {
        $name = $symbol->csharpName();

        if (!$symbol->isAbstract) {
            foreach ($symbol->members as $member) {
                if ($member->isAbstract) {
                    throw $this->error('SP0513', "'{$this->memberName($member)}' is abstract but it is contained in non-abstract type '$name'", $file, $decl);
                }
            }
            for ($base = $this->baseOf($symbol); $base !== null; $base = $this->baseOf($base)) {
                foreach ($base->members as $member) {
                    $implementation = $member->isAbstract ? $this->mostDerived($symbol, $member->name) : $member;
                    if ($implementation === null || $implementation->isAbstract) {
                        throw $this->error('SP0534', "'$name' does not implement inherited abstract member '{$this->memberName($member)}'", $file, $decl);
                    }
                }
            }
        }

        foreach ($this->allInterfaces($symbol) as $interface) {
            $implied = self::IMPLIED_INTERFACE_METHODS[$interface->fqn] ?? null;
            if ($implied !== null && $this->mostDerived($symbol, strstr($implied, '(', true)) === null) {
                throw $this->error('SP0535', "'$name' does not implement interface member '{$interface->csharpName()}<T>.$implied'", $file, $decl);
            }
            foreach ($interface->members as $member) {
                if ($member->isStatic || !in_array($member->kind, ['method', 'property'], true)) {
                    continue;
                }
                if ($symbol->isRecord && in_array($member->name, ['ToString', 'Equals', 'GetHashCode'], true)) {
                    continue; // generated for records
                }
                if ($this->mostDerived($symbol, $member->name) === null) {
                    throw $this->error('SP0535', "'$name' does not implement interface member '{$this->memberName($member)}'", $file, $decl);
                }
            }
        }
    }

    private function baseOf(TypeSymbol $symbol): ?TypeSymbol
    {
        return $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null;
    }

    /** The member a call on $symbol would reach: declared on the class itself or on the nearest base class. */
    private function mostDerived(TypeSymbol $symbol, string $name): ?MemberSymbol
    {
        for ($current = $symbol; $current !== null; $current = $this->baseOf($current)) {
            $member = $current->members[$name] ?? $current->members[$name . '()'] ?? null;
            if ($member !== null) {
                return $member;
            }
        }

        return null;
    }

    /** @return list<TypeSymbol> interfaces of the type, its base classes and their base interfaces */
    private function allInterfaces(TypeSymbol $symbol): array
    {
        $queue = [];
        for ($current = $symbol; $current !== null; $current = $this->baseOf($current)) {
            $queue = [...$queue, ...$current->interfaces];
        }
        $seen = [];
        $result = [];
        while ($queue !== []) {
            $fqn = array_shift($queue);
            if (isset($seen[strtolower($fqn)]) || ($interface = $this->symbols->find($fqn)) === null) {
                continue;
            }
            $seen[strtolower($fqn)] = true;
            $result[] = $interface;
            $queue = [...$queue, ...$interface->interfaces];
        }

        return $result;
    }

    /** "Shape.Area()" / "Shape.Name" as C# prints members in diagnostics. */
    private function memberName(MemberSymbol $member): string
    {
        $name = $member->owner->csharpName() . '.' . $member->name;
        if ($member->kind !== 'method') {
            return $name;
        }

        return $name . '(' . implode(', ', array_map(static fn (ParameterSymbol $p): string => $p->type->describe(), $member->params)) . ')';
    }

    public function contextFor(CompilationUnit $unit, ?NamespaceDecl $ns): ResolutionContext
    {
        $usings = [];
        $aliases = [];
        foreach ([...$unit->usings, ...($ns?->usings ?? [])] as $using) {
            if ($using->alias !== null) {
                $aliases[$using->alias] = $using->name;
            } else {
                $usings[] = $using->name;
            }
        }

        return new ResolutionContext($unit->file, $ns?->name ?? '', $usings, $aliases);
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

    /** @return array<string, Type> */
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
            $type = $this->resolver->resolve($param->type, $ctx, $typeParams);
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
                $this->add($symbol, $property, $file, $member);
            } elseif ($member instanceof MethodDecl) {
                if ($member->kind === 'static_constructor') {
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
                if (isset($methodNames[$key])) {
                    throw $this->error('SP0111', "Method overloading is not supported: '{$decl->name}' already defines a member called '{$member->name}' (PHP method names are case-insensitive)", $file, $member);
                }
                $methodNames[$key] = true;

                $methodTypeParams = $this->typeParamsOf($member, $ctx, $typeParams);
                $params = $this->parameterSymbols($member, $ctx, $methodTypeParams);
                $isStatic = in_array('static', $member->modifiers, true);
                $method = new MemberSymbol(
                    'method',
                    $member->name,
                    $isStatic,
                    $this->resolver->resolve($member->returnType, $ctx, $methodTypeParams),
                    $symbol,
                    $params,
                    $member->typeParams,
                    self::visibility($member->modifiers, $defaultVisibility),
                );
                $method->isAbstract = in_array('abstract', $member->modifiers, true);
                $this->add($symbol, $method, $file, $member);

                if ($member->name === 'Main' && $isStatic) {
                    $this->entryPoints[] = ['type' => $symbol, 'file' => $file, 'node' => $member];
                }
            }
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
                $this->resolver->resolve($param->type, $ctx, $typeParams),
                $param->modifier,
                $param->default !== null || $param->modifier === 'params',
            );
        }

        return $params;
    }

    private function add(TypeSymbol $symbol, MemberSymbol $member, SourceFile $file, Node $at): void
    {
        if (isset($symbol->members[$member->name])) {
            throw $this->error('SP0102', "The type '{$symbol->csharpName()}' already contains a definition for '{$member->name}'", $file, $at);
        }
        $symbol->addMember($member);
    }

    /** @param list<string> $modifiers */
    public static function visibility(array $modifiers, string $default = 'private'): string
    {
        if (in_array('public', $modifiers, true) || in_array('internal', $modifiers, true) && !in_array('protected', $modifiers, true) && !in_array('private', $modifiers, true)) {
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
