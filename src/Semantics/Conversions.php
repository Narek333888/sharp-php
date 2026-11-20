<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * C#'s implicit and explicit conversions between the types SharpPHP knows.
 *
 * The compiler does not know every type (object, dynamic, unconstrained T, untyped PHP code),
 * so a conversion counts as impossible only when both types are known and C# would never
 * allow it.
 */
final class Conversions
{
    public function __construct(private readonly SymbolTable $symbols)
    {
    }

    /**
     * @param bool $isZeroLiteral the value is the literal 0 (which converts to any enum)
     * @param bool $charAsString the target is a parameter of the runtime library, whose string
     *                           parameters stand for C#'s char overloads too ("a,b".Split(','))
     */
    public function isImplicit(Type $from, Type $to, bool $isZeroLiteral = false, bool $charAsString = false): bool
    {
        if ($to->isUnknown() || $to->kind === 'typeparam' || $to->kind === 'void') {
            return true;
        }
        if ($from->kind === 'void') {
            return false;
        }
        if ($from->isUnknown() || $from->kind === 'typeparam') {
            return true;
        }
        if ($from->isNull()) {
            return $to->nullable || !$this->isValueType($to);
        }
        if ($to->isClass() && in_array($to->class, Type::SCALAR_INTERFACES, true)) {
            return true; // IComparable<T>, IEquatable<T>: numbers and strings implement them
        }

        if ($this->isPhpArrayBoundary($from, $to)) {
            return true;
        }

        return match ($from->kind) {
            'int' => in_array($to->kind, ['int', 'float'], true) || ($isZeroLiteral && $this->isEnum($to)),
            'float', 'bool' => $to->kind === $from->kind,
            'array' => in_array($to->kind, ['array', 'iterable'], true),
            'char' => in_array($to->kind, ['char', 'int', 'float'], true) || ($charAsString && $to->isString()),
            'string' => in_array($to->kind, ['string', 'iterable'], true),
            'closure' => $to->kind === 'closure' || ($to->isClass() && strcasecmp((string) $to->class, 'Closure') === 0),
            'iterable' => !$this->isPrimitive($to),
            'class' => $this->isImplicitFromClass($from, $to),
            default => true,
        };
    }

    /**
     * A PHP array where SharpPHP wants a List, Dictionary or HashSet, or the other way round: converted
     * at run time (see ChecksTypes::converted()), so SharpPHP and PHP code can call each other.
     */
    public function isPhpArrayBoundary(Type $from, Type $to): bool
    {
        return ($from->isPhpArray() && $this->collectionName($to, exact: true) !== null)
            || ($to->isPhpArray() && $this->collectionName($from) !== null);
    }

    /**
     * "List", "Dictionary" or "HashSet" for those runtime collections, else null. Classes derived from
     * them count too unless $exact (a PHP array can become a List, not a class derived from it).
     */
    public function collectionName(Type $type, bool $exact = false): ?string
    {
        $symbol = $type->kind === 'class' ? $this->symbols->symbolForType($type) : null;
        if ($symbol === null) {
            return null;
        }
        $prefix = Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\';
        foreach (['List_' => 'List', 'Dictionary' => 'Dictionary', 'HashSet' => 'HashSet'] as $class => $name) {
            if (strcasecmp($symbol->fqn, $prefix . $class) === 0 || (!$exact && $this->symbols->isSubtypeOf($symbol->fqn, $prefix . $class))) {
                return $name;
            }
        }

        return null;
    }

    /** Casts C# accepts where an implicit conversion is not allowed (double -> int, Base -> Derived). */
    public function isExplicit(Type $from, Type $to): bool
    {
        $numeric = static fn (Type $t): bool => in_array($t->kind, ['int', 'float', 'char'], true);
        if ($numeric($from) && $numeric($to)) {
            return true;
        }
        if (($from->isInt() && $this->isEnum($to)) || ($this->isEnum($from) && $to->isInt())) {
            return true;
        }
        $fromSymbol = $this->symbols->symbolForType($from);
        $toSymbol = $this->symbols->symbolForType($to);

        return $fromSymbol !== null && $toSymbol !== null && $this->symbols->isSubtypeOf($toSymbol->fqn, $fromSymbol->fqn);
    }

    /** Types whose variables cannot hold null unless declared nullable (numbers, bool, char, enums). */
    public function isValueType(Type $type): bool
    {
        return $type->isValueType() || $this->isEnum($type);
    }

    public function isEnum(Type $type): bool
    {
        return $this->symbols->symbolForType($type)?->isEnum() ?? false;
    }

    private function isPrimitive(Type $type): bool
    {
        return in_array($type->kind, ['int', 'float', 'bool', 'char', 'string'], true);
    }

    private function isImplicitFromClass(Type $from, Type $to): bool
    {
        $fromSymbol = $this->symbols->symbolForType($from);
        if ($to->isClass()) {
            $toSymbol = $this->symbols->symbolForType($to);

            return $fromSymbol === null || $toSymbol === null || $this->symbols->isSubtypeOf($fromSymbol->fqn, $toSymbol->fqn);
        }
        if ($to->kind === 'iterable') {
            // Collections, including project classes derived from them (class Bag : List<int>).
            return $fromSymbol === null || $fromSymbol->external || $this->symbols->isSubtypeOf($fromSymbol->fqn, 'Traversable');
        }
        if ($to->kind === 'closure') {
            return $fromSymbol === null || strcasecmp($fromSymbol->fqn, 'Closure') === 0;
        }

        // To a primitive or an array: only untyped PHP objects (not the runtime library) might still work.
        return $fromSymbol !== null && $fromSymbol->external && !$fromSymbol->isEnum()
            && !str_starts_with($fromSymbol->fqn, Names::RUNTIME_NAMESPACE . '\\');
    }
}
