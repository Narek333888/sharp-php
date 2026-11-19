<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * Accessibility (private/protected) and writability (get-only, init-only, readonly) of members
 * of project types. PHP would only fail at runtime; C# rejects these at compile time.
 * Members of PHP classes (runtime library, vendor packages) are not checked.
 */
final class AccessRules
{
    public function __construct(private readonly SymbolTable $symbols)
    {
    }

    /** Whether code inside $from (null: outside any class) may use something with $visibility declared in $owner. */
    public function canAccess(?TypeSymbol $from, TypeSymbol $owner, string $visibility): bool
    {
        return match ($visibility) {
            'private' => $from !== null && strcasecmp($from->fqn, $owner->fqn) === 0,
            'protected' => $from !== null && $this->symbols->isSubtypeOf($from->fqn, $owner->fqn),
            default => true,
        };
    }

    public function accessViolation(MemberSymbol $member, ?TypeSymbol $from): ?Violation
    {
        if ($member->owner->external || $this->canAccess($from, $this->declaringType($member), $member->visibility)) {
            return null;
        }

        return new Violation('SP0122', "'{$member->displayName()}' is inaccessible due to its protection level");
    }

    /**
     * The class that first declared the member: an override is as accessible as the member it
     * overrides (Base code may call a protected method that Derived overrides).
     */
    private function declaringType(MemberSymbol $member): TypeSymbol
    {
        $declaring = $member->owner;
        for ($base = $this->baseOf($declaring); $base !== null; $base = $this->baseOf($base)) {
            if (isset($base->members[$member->name])) {
                $declaring = $base;
            }
        }

        return $declaring;
    }

    private function baseOf(TypeSymbol $type): ?TypeSymbol
    {
        return $type->baseClass !== null ? $this->symbols->find($type->baseClass) : null;
    }

    public function constructorViolation(TypeSymbol $type, ?TypeSymbol $from): ?Violation
    {
        if ($type->constructorVisibility === null || $this->canAccess($from, $type, $type->constructorVisibility)) {
            return null;
        }
        return new Violation('SP0122', "'{$type->csharpName()}.{$type->shortName()}()' is inaccessible due to its protection level");
    }

    /**
     * @param ?TypeSymbol $constructorOf the class whose constructor the assignment is in, if any
     * @param bool $inInitializer the assignment is part of an object initializer or a "with" expression
     */
    public function writeViolation(MemberSymbol $member, ?TypeSymbol $from, ?TypeSymbol $constructorOf, bool $inInitializer = false): ?Violation
    {
        if ($member->owner->external) {
            return null;
        }
        $name = $member->displayName();
        // Get-only properties and readonly fields: only the declaring class's constructor.
        // Init accessors: derived classes' constructors too (on this/base).
        $inOwnConstructor = $constructorOf !== null && strcasecmp($constructorOf->fqn, $member->owner->fqn) === 0;
        $inDerivedConstructor = $constructorOf !== null && $this->symbols->isSubtypeOf($constructorOf->fqn, $member->owner->fqn);

        if ($member->kind === 'field') {
            return $member->isReadonly && !$inOwnConstructor
                ? new Violation('SP0191', 'A readonly field cannot be assigned to (except in a constructor or init-only setter of the type in which the field is defined or a variable initializer)')
                : null;
        }
        if ($member->kind !== 'property') {
            return null;
        }
        if ($member->setter === null) {
            return $member->isAutoProperty && $inOwnConstructor
                ? null
                : new Violation('SP0200', "Property or indexer '$name' cannot be assigned to -- it is read only");
        }
        if ($member->setter === 'init') {
            return $inDerivedConstructor || $inInitializer
                ? null
                : new Violation('SP8852', "Init-only property or indexer '$name' can only be assigned in an object initializer, or on 'this' or 'base' in an instance constructor or an 'init' accessor");
        }

        return $this->canAccess($from, $this->declaringType($member), $member->setter)
            ? null
            : new Violation('SP0272', "The property or indexer '$name' cannot be used in this context because the set accessor is inaccessible");
    }
}
