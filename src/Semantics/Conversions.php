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
            'int' => ($to->kind === 'int' && self::integralConverts($from->integralName(), $to->integralName()))
                || $to->kind === 'float' || ($isZeroLiteral && $this->isEnum($to)),
            'float', 'bool' => $to->kind === $from->kind,
            // Arrays and IEnumerable<T> are covariant: Dog[] is an Animal[] and an IEnumerable<Animal>.
            'array' => in_array($to->kind, ['array', 'iterable'], true) && $this->argumentConverts($from->element(), $to->element(), 'out'),
            'char' => in_array($to->kind, ['char', 'float'], true) || ($to->kind === 'int' && self::integralConverts('ushort', $to->integralName()))
                || ($charAsString && $to->isString()),
            'string' => in_array($to->kind, ['string', 'iterable'], true),
            'closure' => ($to->kind === 'closure' && $this->delegateConverts($from, $to)) || ($to->isClass() && strcasecmp((string) $to->class, 'Closure') === 0),
            'iterable' => !$this->isPrimitive($to) && ($to->kind !== 'iterable' || $this->argumentConverts($from->element(), $to->element(), 'out')),
            'class' => $this->isImplicitFromClass($from, $to),
            default => true,
        };
    }

    /**
     * C#'s implicit numeric conversions between integral types: to a type that holds every value
     * (byte -> int, int -> long, uint -> ulong), never to a narrower one (int -> byte, long -> int).
     */
    public static function integralConverts(string $from, string $to): bool
    {
        if ($from === $to || $from === Type::UNSIZED || $to === Type::UNSIZED) {
            return true;
        }

        return in_array($to, match ($from) {
            'sbyte' => ['short', 'int', 'long'],
            'byte' => ['short', 'ushort', 'int', 'uint', 'long', 'ulong'],
            'short' => ['int', 'long'],
            'ushort' => ['int', 'uint', 'long', 'ulong'],
            'int' => ['long'],
            'uint' => ['long', 'ulong'],
            default => [],
        }, true);
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
        if ($fromSymbol === null || $toSymbol === null || !$this->symbols->isSubtypeOf($toSymbol->fqn, $fromSymbol->fqn)) {
            return false;
        }
        // A downcast (Animal to Dog, IBox<Animal> to Box<Animal>) keeps the generic arguments: a
        // List<Dog> never is a List<Animal>, so there is no cast either. Interfaces may be implemented
        // with other arguments by some class, so casts between them are allowed, as in C#.
        return $fromSymbol->isInterface() || $toSymbol->isInterface() || $this->genericArgumentsConvert($toSymbol, $to, $fromSymbol, $from);
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

    /**
     * C#'s rules for the generic arguments of a conversion to a generic class or interface: List<Dog>
     * is not a List<Animal> (invariant), IBox<Dog> is an IBox<Animal> when IBox<out T>, ISink<Animal>
     * is an ISink<Dog> when ISink<in T>.
     */
    private function genericArgumentsConvert(TypeSymbol $fromSymbol, Type $from, TypeSymbol $toSymbol, Type $to): bool
    {
        if ($toSymbol->typeParams === [] || $to->args === []) {
            return true;
        }
        $args = $this->symbols->supertypeArgs($fromSymbol, $from->args, $toSymbol->fqn);
        if ($args === null) {
            return true; // not known (a PHP class): PHP checks at run time
        }
        foreach ($toSymbol->typeParams as $i => $name) {
            if (isset($args[$i], $to->args[$i]) && !$this->argumentConverts($args[$i], $to->args[$i], $toSymbol->variance[$name] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A generic argument $from where $to is expected: the same type, or, for a variant parameter, a
     * reference conversion (out: Dog to Animal, in: Animal to Dog). Value types are never variant:
     * IEnumerable<int> is not an IEnumerable<object>.
     *
     * @param 'in'|'out'|null $variance
     */
    public function argumentConverts(Type $from, Type $to, ?string $variance): bool
    {
        if ($from->isUnknown() || $to->isUnknown() || $from->kind === 'typeparam' || $to->kind === 'typeparam') {
            return true;
        }
        if ($this->sameType($from, $to)) {
            return true;
        }
        if ($variance === null || !$this->isReferenceType($from) || !$this->isReferenceType($to)) {
            return false;
        }

        return $variance === 'out' ? $this->isImplicit($from, $to) : $this->isImplicit($to, $from);
    }

    /** Func<in T, out TResult> / Action<in T>: parameters are contravariant, the result covariant. */
    private function delegateConverts(Type $from, Type $to): bool
    {
        // Untyped delegates (a PHP Closure), or a lambda whose parameter count differs: PHP decides.
        if ($from->args === [] || $to->args === [] || count($from->args) !== count($to->args)) {
            return true;
        }
        $last = count($to->args) - 1;
        foreach ($to->args as $i => $toArg) {
            $fromArg = $from->args[$i];
            if ($i === $last) {
                // Any result where none is used (Action) or where it is untyped (a PHP "mixed" callback).
                if ($toArg->kind !== 'void' && !$toArg->isUnknown() && ($fromArg->kind === 'void' || !$this->argumentConverts($fromArg, $toArg, 'out'))) {
                    return false;
                }
            } elseif (!$this->argumentConverts($toArg, $fromArg, 'out')) {
                return false;
            }
        }

        return true;
    }

    /** The same type, ignoring nullable annotations of reference types (string? and string). */
    private function sameType(Type $a, Type $b): bool
    {
        $a = $this->isValueType($a) ? $a : $a->withNullable(false);
        $b = $this->isValueType($b) ? $b : $b->withNullable(false);

        return $a->describe() === $b->describe();
    }

    /** Classes (not enums or structs), strings, arrays, sequences, delegates: what variance applies to. */
    private function isReferenceType(Type $type): bool
    {
        if (in_array($type->kind, ['string', 'array', 'iterable', 'closure', 'mixed', 'null'], true)) {
            return true;
        }
        if ($type->kind !== 'class') {
            return false;
        }
        $symbol = $this->symbols->symbolForType($type);

        return $symbol === null || (!$symbol->isEnum() && !$symbol->isStruct);
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
            if ($fromSymbol === null || $toSymbol === null) {
                return true;
            }
            if (!$this->symbols->isSubtypeOf($fromSymbol->fqn, $toSymbol->fqn)) {
                return false;
            }

            return $this->genericArgumentsConvert($fromSymbol, $from, $toSymbol, $to);
        }
        if ($to->kind === 'iterable') {
            // Collections, including project classes derived from them (class Bag : List<int>).
            if ($fromSymbol !== null && !$fromSymbol->external && !$this->symbols->isSubtypeOf($fromSymbol->fqn, 'Traversable')) {
                return false;
            }
            $element = $fromSymbol?->elementType !== null ? $fromSymbol->elementType->substitute($fromSymbol->substitutionFor($from)) : null;

            return $element === null || $this->argumentConverts($element, $to->element(), 'out');
        }
        if ($to->kind === 'closure') {
            return $fromSymbol === null || strcasecmp($fromSymbol->fqn, 'Closure') === 0;
        }

        // To a primitive or an array: only untyped PHP objects (not the runtime library) might still work.
        return $fromSymbol !== null && $fromSymbol->external && !$fromSymbol->isEnum()
            && !str_starts_with($fromSymbol->fqn, Names::RUNTIME_NAMESPACE . '\\');
    }
}
