<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\ParameterSymbol;
use SharpPhp\Semantics\SymbolTable;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\CompilationUnit;
use SharpPhp\Syntax\TypeDecl;

/**
 * Checks that every class implements its interfaces and inherited abstract members, and has
 * no abstract members unless it is abstract itself. PHP would only report these as a fatal
 * error when the class is loaded; C# rejects them at compile time.
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
                if ($implementation === null || !$this->matches($implementation, $member, phpInterface: $interface->external)) {
                    throw $this->error('SP0535', "'$name' does not implement interface member '{$member->displayName()}'", $file, $decl);
                }
            }
        }
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
