<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\SymbolTable;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\TypeDecl;

/**
 * Checks that every class implements its interfaces and inherited abstract members, and has
 * no abstract members unless it is abstract itself. PHP would only report these as a fatal
 * error when the class is loaded (or not at all: PHP's int is C#'s int and long); C# rejects them
 * at compile time. A member of a SharpPHP interface is implemented as C# requires: by a public
 * member of the same kind, parameters and return type (the interface's type arguments put in).
 *
 * Runs after the Declarator has defined the members of every type in the project.
 */
final class ImplementationVerifier
{
    /** Runtime interfaces that do not declare their method in PHP (see IComparable), with the method C# requires. */
    private const IMPLIED_INTERFACE_METHODS = [
        Names::RUNTIME_NAMESPACE . '\\System\\IComparable' => 'CompareTo',
        Names::RUNTIME_NAMESPACE . '\\System\\IEquatable' => 'Equals',
    ];

    /**
     * System.Object members: every C# object has them, so an interface that redeclares one
     * (string ToString();) is satisfied without a declaration; the emitter then generates it.
     */
    private const OBJECT_MEMBERS = ['ToString', 'Equals', 'GetHashCode'];

    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly Declarator $declarator,
    ) {
    }

    /** @throws CompileError */
    public function verify(CompilationUnit $unit): void
    {
        foreach ($unit->namespaces as $ns) {
            foreach ($ns->types as $decl) {
                $symbol = $this->declarator->symbolOf($decl);
                if (!$symbol->isInterface() && !$symbol->isEnum() && $symbol->kind !== 'delegate') {
                    $this->verifyType($decl, $symbol, $unit->file);
                }
            }
        }
    }

    /** @var array<string, true> classes whose hiding methods are resolved */
    private array $hidingResolved = [];

    /**
     * Methods that hide a method of a SharpPHP base class get a PHP name of their own (see
     * MemberSymbol::$phpName); an override takes the name of the method it overrides, and overriding a
     * method that is not virtual is CS0506. Base classes first.
     */
    private function resolveHiding(TypeSymbol $symbol, SourceFile $file, TypeDecl $decl): void
    {
        if (isset($this->hidingResolved[$symbol->fqn])) {
            return;
        }
        $this->hidingResolved[$symbol->fqn] = true;
        $base = $this->baseOf($symbol);
        if ($base === null || $base->external) {
            return;
        }
        $this->resolveHiding($base, $file, $decl);
        $interfaceMembers = [];
        foreach ($this->symbols->interfacesOf($symbol) as $interface) {
            foreach ($interface->members as $m) {
                $interfaceMembers[strtolower($m->name)] = true;
            }
        }
        foreach ($symbol->members as $member) {
            if ($member->kind !== 'method' || $member->isStatic || $member->isLocalFunction || $member->name === '__construct') {
                continue;
            }
            $hidden = $this->mostDerived($base, $member->name);
            if ($hidden === null || $hidden->kind !== 'method' || $hidden->isStatic || $hidden->owner->external) {
                continue;
            }
            if ($member->isOverride) {
                if (!$hidden->isVirtual) {
                    throw $this->error('SP0506', "'{$member->displayName()}': cannot override inherited member '{$hidden->displayName()}' because it is not marked virtual, abstract, or override", $file, $decl);
                }
                $member->phpName = $hidden->phpName;
            } elseif ($hidden->isAbstract && !$symbol->isAbstract) {
                // Without override the method hides the abstract one, which stays unimplemented (CS0534).
                throw $this->error('SP0534', "'{$symbol->csharpName()}' does not implement inherited abstract member '{$hidden->displayName()}'", $file, $decl);
            } elseif (!isset($interfaceMembers[strtolower($member->name)])) {
                $member->phpName = $member->name . '__' . $symbol->shortName();
            }
        }
    }

    /**
     * Checks one class, struct or record after resolveHiding(): unless it is abstract, SP0513 for an abstract member
     * and SP0534 for an inherited abstract member it does not implement; then the members of all its interfaces,
     * inherited ones too: SP0535 when one is missing (CompareTo of IComparable<T> and Equals of IEquatable<T> too),
     * SP0736 for a static and SP0737 for a non-public implementation, and verifyImplementation() for a SharpPHP
     * interface.
     */
    private function verifyType(TypeDecl $decl, TypeSymbol $symbol, SourceFile $file): void
    {
        $name = $symbol->csharpName();
        $this->resolveHiding($symbol, $file, $decl);

        if (!$symbol->isAbstract) {
            foreach ($symbol->members as $member) {
                if ($member->isAbstract) {
                    throw $this->error('SP0513', "'{$member->displayName()}' is abstract but it is contained in non-abstract type '$name'", $file, $decl);
                }
            }
            for ($base = $this->baseOf($symbol); $base !== null; $base = $this->baseOf($base)) {
                foreach ($base->members as $member) {
                    $implementation = $member->isAbstract ? $this->mostDerived($symbol, $member->name) : $member;
                    if ($implementation === null || $implementation->isAbstract) {
                        throw $this->error('SP0534', "'$name' does not implement inherited abstract member '{$member->displayName()}'", $file, $decl);
                    }
                }
            }
        }

        foreach ($this->symbols->interfacesOf($symbol) as $interface) {
            $implied = self::IMPLIED_INTERFACE_METHODS[$interface->fqn] ?? null;
            if ($implied !== null && !$this->isGenerated($symbol, $implied) && $this->mostDerived($symbol, $implied) === null) {
                throw $this->error('SP0535', "'$name' does not implement interface member '{$interface->csharpName()}<T>.$implied(T)'", $file, $decl);
            }
            foreach ($interface->members as $member) {
                if ($member->isStatic || !in_array($member->kind, ['method', 'property'], true)) {
                    continue;
                }
                if ($this->isGenerated($symbol, $member->name)) {
                    continue;
                }
                $implementation = $this->mostDerived($symbol, $member->name, caseInsensitive: $interface->external);
                if ($implementation !== null && $implementation->isStatic) {
                    throw $this->error('SP0736', "'{$implementation->displayName()}' cannot implement interface member '{$member->displayName()}' because it is static", $file, $decl);
                }
                $arguments = $interface->external ? null : $this->symbols->supertypeArgs($symbol, self::ownTypeParams($symbol), $interface->fqn);
                if ($arguments === null || ($implementation !== null && $implementation->owner->external)) {
                    // A PHP interface (Countable), or one implemented by a PHP or runtime base class: as PHP checks it.
                    if ($implementation === null || !$this->matches($implementation, $member, phpInterface: $interface->external)) {
                        throw $this->error('SP0535', "'$name' does not implement interface member '{$member->displayName()}'", $file, $decl);
                    }
                    if (!$implementation->owner->external && $implementation->visibility !== 'public') {
                        throw $this->error('SP0737', "'$name' does not implement interface member '{$member->displayName()}'. '{$implementation->displayName()}' cannot implement an interface member because it is not public.", $file, $decl);
                    }
                    continue;
                }
                $subst = [];
                foreach ($interface->typeParams as $i => $param) {
                    $subst[$param] = $arguments[$i] ?? Type::mixed();
                }
                $this->verifyImplementation($symbol, $interface, $member, $implementation, $subst, $file, $decl);
            }
        }
    }

    /**
     * C#'s rules for the member implementing $member of a SharpPHP interface: CS0535 without one of the same
     * kind and parameters (types and ref/out), CS0737 when it is not public, CS0738 when its type differs,
     * and for a property with a set (init) accessor, a public set (init) accessor (CS0535, CS8854, CS0277).
     *
     * @param array<string, Type> $subst the interface's type parameters => what $class implements it with
     */
    private function verifyImplementation(TypeSymbol $class, TypeSymbol $interface, MemberSymbol $member, ?MemberSymbol $implementation, array $subst, SourceFile $file, TypeDecl $decl): void
    {
        $subst = array_diff_key($subst, array_flip($member->typeParams));
        $wanted = $this->describe($member, self::genericName($interface, $subst), $subst);
        $className = self::genericName($class);
        $missing = "'$className' does not implement interface member '$wanted'";
        // The implementation in the class's terms: B<T>.Add(T) of a class C : B<int> takes an int.
        $own = [];
        if ($implementation !== null && $implementation->owner !== $class) {
            $arguments = $this->symbols->supertypeArgs($class, self::ownTypeParams($class), $implementation->owner->fqn);
            foreach ($implementation->owner->typeParams as $i => $param) {
                $own[$param] = $arguments[$i] ?? Type::mixed();
            }
        }
        // A generic method's type parameters are matched by position: U Get<U>(U x) implements T Get<T>(T x).
        if ($implementation !== null && count($implementation->typeParams) === count($member->typeParams)) {
            foreach ($implementation->typeParams as $i => $param) {
                $own[$param] = Type::typeParam($member->typeParams[$i]);
            }
        }
        if ($implementation === null || $implementation->kind !== $member->kind || !$this->sameParameters($implementation, $member, $own, $subst)) {
            throw $this->error('SP0535', $missing, $file, $decl);
        }
        $implementing = $this->describe($implementation, self::genericName($implementation->owner), []);
        if ($implementation->visibility !== 'public' || $implementation->isInternal) {
            throw $this->error('SP0737', "$missing. '$implementing' cannot implement an interface member because it is not public.", $file, $decl);
        }
        $type = $member->type->substitute($subst);
        if (!$this->sameType($implementation->type->substitute($own), $type)) {
            throw $this->error('SP0738', "$missing. '$implementing' cannot implement '$wanted' because it does not have the matching return type of '{$type->describe()}'.", $file, $decl);
        }
        if ($member->kind === 'property' && $member->setter !== null) {
            $accessor = $member->setter === 'init' ? 'init' : 'set';
            if ($implementation->setter === null) {
                throw $this->error('SP0535', "'$className' does not implement interface member '$wanted.$accessor'", $file, $decl);
            }
            $own = $implementation->setter === 'init' ? 'init' : 'set';
            if ($own !== $accessor) {
                throw $this->error('SP8854', "'$className' does not implement interface member '$wanted.$accessor'. '$implementing.$own' cannot implement '$wanted.$accessor'.", $file, $decl);
            }
            if ($own === 'set' && $implementation->setter !== 'public') {
                throw $this->error('SP0277', "'$className' does not implement interface member '$wanted.set'. '$implementing.set' is not public.", $file, $decl);
            }
        }
    }

    /**
     * The same parameters: as many type parameters, and parameters of the same types with the same ref,
     * out or in (names, default values and params may differ).
     *
     * @param array<string, Type> $own the implementation's type parameters => the class's view of them
     * @param array<string, Type> $subst the interface's type parameters => the class's arguments
     */
    private function sameParameters(MemberSymbol $implementation, MemberSymbol $member, array $own, array $subst): bool
    {
        if (count($implementation->typeParams) !== count($member->typeParams) || count($implementation->params) !== count($member->params)) {
            return false;
        }
        foreach ($member->params as $i => $param) {
            $other = $implementation->params[$i];
            if (self::passing($param) !== self::passing($other) || !$this->sameType($other->type->substitute($own), $param->type->substitute($subst))) {
                return false;
            }
        }

        return true;
    }

    /** ref, out or in; null for a parameter passed by value (params included). */
    private static function passing(ParameterSymbol $param): ?string
    {
        return in_array($param->modifier, ['ref', 'out', 'in'], true) ? $param->modifier : null;
    }

    /**
     * The same type, as C# matches signatures: string? is string (an annotation), but int? is not int,
     * List<string?> is List<string>, and object is dynamic.
     */
    private function sameType(Type $a, Type $b): bool
    {
        return $this->identity($a) === $this->identity($b);
    }

    private function identity(Type $type): string
    {
        $head = match ($type->kind) {
            'int' => 'int:' . $type->integralName(),
            'float' => 'float:' . $type->floatingName(),
            'typeparam' => 'typeparam:' . $type->param,
            'class' => 'class:' . strtolower(ltrim((string) $type->class, '\\')),
            default => $type->kind,
        };
        $symbol = $type->kind === 'class' ? $this->symbols->symbolForType($type) : null;
        $valueType = $type->isValueType() || ($symbol !== null && ($symbol->isEnum() || $symbol->isStruct));
        $args = array_map(fn (Type $t): string => $this->identity($t), $type->args);

        return $head . ($args !== [] ? '<' . implode(',', $args) . '>' : '') . ($type->nullable && $valueType ? '?' : '');
    }

    /**
     * A member as C# names it in these errors: "BaseRepository<int>.Add(int)", "IG.Get<T>(T)", "IP.Count".
     *
     * @param array<string, Type> $subst
     */
    private function describe(MemberSymbol $member, string $owner, array $subst): string
    {
        $name = $owner . '.' . $member->name;
        if ($member->kind !== 'method') {
            return $name;
        }
        $params = array_map(
            static fn (ParameterSymbol $p): string => ($p->modifier !== null ? $p->modifier . ' ' : '') . $p->type->substitute($subst)->describe(),
            $member->params,
        );

        return $name . ($member->typeParams !== [] ? '<' . implode(', ', $member->typeParams) . '>' : '') . '(' . implode(', ', $params) . ')';
    }

    /**
     * "BaseRepository<int>" for the interface as a class implements it, "R<U>" for a generic class.
     *
     * @param array<string, Type> $subst
     */
    private static function genericName(TypeSymbol $symbol, array $subst = []): string
    {
        if ($symbol->typeParams === []) {
            return $symbol->csharpName();
        }
        $args = array_map(static fn (string $p): string => ($subst[$p] ?? Type::typeParam($p))->describe(), $symbol->typeParams);

        return $symbol->csharpName() . '<' . implode(', ', $args) . '>';
    }

    /** @return list<Type> a generic type's own type parameters, as its members' types name them */
    private static function ownTypeParams(TypeSymbol $symbol): array
    {
        return array_map(static fn (string $p): Type => Type::typeParam($p), $symbol->typeParams);
    }

    private function baseOf(TypeSymbol $symbol): ?TypeSymbol
    {
        return $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null;
    }

    /**
     * The member a call on $symbol reaches: declared on the class itself or on the nearest base class.
     *
     * @param bool $caseInsensitive match like PHP does (implementing a PHP interface such as Countable)
     */
    private function mostDerived(TypeSymbol $symbol, string $name, bool $caseInsensitive = false): ?MemberSymbol
    {
        for ($current = $symbol; $current !== null; $current = $this->baseOf($current)) {
            $member = $current->members[$name] ?? $current->members[$name . '()'] ?? null;
            if ($member === null && $caseInsensitive) {
                foreach ($current->members as $candidate) {
                    if ($candidate->kind === 'method' && strcasecmp($candidate->name, $name) === 0) {
                        $member = $candidate;
                    }
                }
            }
            if ($member !== null) {
                return $member;
            }
        }

        return null;
    }

    /**
     * A method for a method with as many parameters, a property or field for a property. (PHP would refuse the class otherwise.)
     * A PHP interface's method may also be implemented with more parameters when they are optional,
     * as PHP allows: List<T>.count(predicate = null) implements Countable::count().
     */
    private function matches(MemberSymbol $implementation, MemberSymbol $interfaceMember, bool $phpInterface = false): bool
    {
        if ($interfaceMember->kind === 'method') {
            if ($implementation->kind !== 'method') {
                return false;
            }
            $extra = array_slice($implementation->params, count($interfaceMember->params));
            if ($phpInterface && count($implementation->params) > count($interfaceMember->params)) {
                return array_filter($extra, static fn (ParameterSymbol $p): bool => !$p->optional) === [];
            }

            return count($implementation->params) === count($interfaceMember->params);
        }

        return $implementation->isValue();
    }

    /** System.Object members the compiler generates for records (and for classes, see Emitter). */
    private function isGenerated(TypeSymbol $symbol, string $name): bool
    {
        return in_array($name, self::OBJECT_MEMBERS, true) && ($symbol->isRecord || $this->mostDerived($symbol, $name) === null);
    }


    private function error(string $code, string $message, SourceFile $file, TypeDecl $at): CompileError
    {
        return CompileError::at($code, $message, $file, $at->line, $at->column);
    }
}
