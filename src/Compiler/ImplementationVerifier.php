<?php

declare(strict_types=1);

namespace SharpPhp\Compiler;

use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
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
        Names::RUNTIME_NAMESPACE . '\\System\\IComparable' => 'CompareTo(T)',
        Names::RUNTIME_NAMESPACE . '\\System\\IEquatable' => 'Equals(T)',
    ];

    /** System.Object members that records get generated implementations of. */
    private const RECORD_GENERATED = ['ToString', 'Equals', 'GetHashCode'];

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
                if (!$symbol->isInterface() && !$symbol->isEnum()) {
                    $this->verifyType($decl, $symbol, $unit->file);
                }
            }
        }
    }

    private function verifyType(TypeDecl $decl, TypeSymbol $symbol, SourceFile $file): void
    {
        $name = $symbol->csharpName();

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

        foreach ($this->allInterfaces($symbol) as $interface) {
            $implied = self::IMPLIED_INTERFACE_METHODS[$interface->fqn] ?? null;
            if ($implied !== null && $this->mostDerived($symbol, (string) strstr($implied, '(', true)) === null) {
                throw $this->error('SP0535', "'$name' does not implement interface member '{$interface->csharpName()}<T>.$implied'", $file, $decl);
            }
            foreach ($interface->members as $member) {
                if ($member->isStatic || !in_array($member->kind, ['method', 'property'], true)) {
                    continue;
                }
                if ($symbol->isRecord && in_array($member->name, self::RECORD_GENERATED, true)) {
                    continue;
                }
                if ($this->mostDerived($symbol, $member->name) === null) {
                    throw $this->error('SP0535', "'$name' does not implement interface member '{$member->displayName()}'", $file, $decl);
                }
            }
        }
    }

    private function baseOf(TypeSymbol $symbol): ?TypeSymbol
    {
        return $symbol->baseClass !== null ? $this->symbols->find($symbol->baseClass) : null;
    }

    /** The member a call on $symbol reaches: declared on the class itself or on the nearest base class. */
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

    /** @return list<TypeSymbol> interfaces of the type, of its base classes and their base interfaces */
    private function allInterfaces(TypeSymbol $symbol): array
    {
        $queue = [];
        for ($current = $symbol; $current !== null; $current = $this->baseOf($current)) {
            array_push($queue, ...$current->interfaces);
        }
        $seen = [];
        $result = [];
        while ($queue !== []) {
            $fqn = array_shift($queue);
            $interface = $this->symbols->find($fqn);
            if ($interface === null || isset($seen[strtolower($fqn)])) {
                continue;
            }
            $seen[strtolower($fqn)] = true;
            $result[] = $interface;
            array_push($queue, ...$interface->interfaces);
        }

        return $result;
    }

    private function error(string $code, string $message, SourceFile $file, TypeDecl $at): CompileError
    {
        return CompileError::at($code, $message, $file, $at->line, $at->column);
    }
}
