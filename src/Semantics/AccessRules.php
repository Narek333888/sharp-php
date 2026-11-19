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
        if ($member->owner->external || $this->canAccess($from, $member->owner, $member->visibility)) {
            return null;
        }

        return new Violation('SP0122', "'{$member->displayName()}' is inaccessible due to its protection level");
    }

    public function constructorViolation(TypeSymbol $type, ?TypeSymbol $from): ?Violation
    {
        if ($type->constructorVisibility === null || $this->canAccess($from, $type, $type->constructorVisibility)) {
            return null;
        }
        $name = $type->csharpName();
        $short = substr((string) strrchr('.' . $name, '.'), 1);

        return new Violation('SP0122', "'$name.$short()' is inaccessible due to its protection level");
    }

    /**
     * @param bool $inOwnConstructor the assignment is in a constructor of the member's class
     * @param bool $inInitializer the assignment is part of an object initializer or a "with" expression
     */
    public function writeViolation(MemberSymbol $member, ?TypeSymbol $from, bool $inOwnConstructor, bool $inInitializer = false): ?Violation
    {
        if ($member->owner->external) {
            return null;
        }
        $name = $member->displayName();

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
            return $inOwnConstructor || $inInitializer
                ? null
                : new Violation('SP8852', "Init-only property or indexer '$name' can only be assigned in an object initializer, or on 'this' or 'base' in an instance constructor or an 'init' accessor");
        }

        return $this->canAccess($from, $member->owner, $member->setter)
            ? null
            : new Violation('SP0272', "The property or indexer '$name' cannot be used in this context because the set accessor is inaccessible");
    }
}
