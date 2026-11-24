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
            'float' => $to->kind === 'float' && self::floatingConverts($from->floatingName(), $to->floatingName()),
            'bool' => $to->kind === 'bool',
            // Arrays are covariant: Dog[] is an Animal[], an IEnumerable<Animal> and an IList<Animal>.
            'array' => in_array($to->kind, ['array', 'iterable'], true) && $this->argumentConverts($from->element(), $to->element(), 'out'),
            'char' => in_array($to->kind, ['char', 'float'], true) || ($to->kind === 'int' && self::integralConverts('ushort', $to->integralName()))
                || ($charAsString && $to->isString()),
            'string' => $to->kind === 'string' || ($to->kind === 'iterable' && $to->collectionInterface() === 'IEnumerable'),
            'closure' => ($to->kind === 'closure' && $this->delegateConverts($from, $to)) || ($to->isClass() && strcasecmp((string) $to->class, 'Closure') === 0),
            'iterable' => $to->kind === 'iterable'
                ? $from->isCollection($to->collectionInterface()) && $this->argumentConverts($from->element(), $to->element(), self::collectionVariance($to))
                : $this->sequenceToClass($to),
            'class' => $this->isImplicitFromClass($from, $to),
            default => true,
        };
    }

    /**
     * Whether a receiver of type $from can be the "this" argument of an extension method taking $to: C#
     * allows an identity, implicit reference or boxing conversion only. 5.M() does not call M(this long x)
     * or M(this int? x), though an int converts to both; it calls M(this object x) or M(this IComparable<int> x).
     */
    public function isReceiverConversion(Type $from, Type $to): bool
    {
        if (!$this->isImplicit($from, $to)) {
            return false;
        }
        if ($from->isUnknown() || $from->kind === 'typeparam' || $to->isUnknown() || $to->kind === 'typeparam' || !$this->isValueType($from)) {
            return true;
        }

        // A value type: boxed (to object or an interface it implements), or of the same type.
        return !$this->isValueType($to) || $this->sameValueType($from, $to);
    }

    /** int and int, int? and int?; a number of PHP code (of unknown size) is of any size of its kind. */
    private function sameValueType(Type $a, Type $b): bool
    {
        if ($a->nullable !== $b->nullable || $a->kind !== $b->kind) {
            return false;
        }

        return match ($a->kind) {
            'int' => $a->integralName() === $b->integralName() || in_array(Type::UNSIZED, [$a->integralName(), $b->integralName()], true),
            'float' => $a->floatingName() === $b->floatingName() || in_array(Type::UNSIZED, [$a->floatingName(), $b->floatingName()], true),
            default => $a->withNullable(false)->equals($b->withNullable(false)),
        };
    }

    /**
     * C#'s implicit conversions between double, float and decimal: float to double only (double to float,
     * and either to decimal or back, need a cast). A float of PHP code converts to and from all.
     */
    public static function floatingConverts(string $from, string $to): bool
    {
        return $from === $to || $from === Type::UNSIZED || $to === Type::UNSIZED || ($from === 'float' && $to === 'double');
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
     * at run time (see ChecksTypes::converted()), so SharpPHP and PHP code can call each other. A
     * sequence (a LINQ query: implode(", ", names.Where(...))) becomes a PHP array too.
     */
    public function isPhpArrayBoundary(Type $from, Type $to): bool
    {
        return ($from->isPhpArray() && $this->collectionName($to, exact: true) !== null)
            || ($to->isPhpArray() && ($this->collectionName($from) !== null || $from->kind === 'iterable'));
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
        // (List<int>)query, (IList<int>)sequence, (IList<int>)set: checked when the program runs.
        if (($from->kind === 'iterable' && ($to->kind === 'iterable' || $to->kind === 'array' || $to->isClass()))
            || ($from->isClass() && $to->kind === 'iterable')) {
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

    /**
     * IEnumerable<T>, IReadOnlyCollection<T> and IReadOnlyList<T> are covariant (out T); ICollection<T>
     * and IList<T> are not.
     *
     * @return 'out'|null
     */
    private static function collectionVariance(Type $interface): ?string
    {
        return in_array($interface->collectionInterface(), ['ICollection', 'IList'], true) ? null : 'out';
    }

    /**
     * A sequence where a class is expected: as in C#, a List<T> = someEnumerable needs a cast (the
     * value may be a query or another collection), except for a PHP class (not SharpPHP's library),
     * which PHP checks when the value arrives.
     */
    private function sequenceToClass(Type $to): bool
    {
        if (!$to->isClass()) {
            return false;
        }
        $symbol = $this->symbols->symbolForType($to);

        return $symbol === null || ($symbol->external && !str_starts_with($symbol->fqn, Names::RUNTIME_NAMESPACE . '\\'));
    }

    /**
     * The collection interfaces a runtime collection implements, as its .NET type does (Dictionary is
     * an ICollection of its pairs, a Queue only an IReadOnlyCollection); null for other classes.
     *
     * @return list<string>|null
     */
    private function collectionInterfacesOf(TypeSymbol $symbol): ?array
    {
        $generic = Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\';
        foreach ([
            'List_' => ['IList', 'ICollection', 'IReadOnlyList', 'IReadOnlyCollection', 'IEnumerable'],
            'HashSet' => ['ICollection', 'IReadOnlyCollection', 'IEnumerable'],
            'Dictionary' => ['ICollection', 'IReadOnlyCollection', 'IEnumerable'],
            'Queue' => ['IReadOnlyCollection', 'IEnumerable'],
            'Stack' => ['IReadOnlyCollection', 'IEnumerable'],
        ] as $class => $interfaces) {
            if ($this->symbols->isSubtypeOf($symbol->fqn, $generic . $class)) {
                return $interfaces;
            }
        }

        return null;
    }

    /**
     * Implicit conversions from a class type: to a class or interface it derives from (generic arguments by variance);
     * to a sequence (IEnumerable<T>, IList<T> ...) when it is a collection implementing that interface, as its .NET
     * type does, with a convertible element type; to a delegate only from Closure; to a primitive or an array only from
     * a PHP class (not an enum) outside the runtime library. A class the compiler does not know converts to classes,
     * sequences and delegates.
     */
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
            $interface = $to->collectionInterface();
            if ($fromSymbol !== null && $interface !== 'IEnumerable') {
                // The runtime collections implement what their .NET types do; a PHP class, what its PHP
                // interfaces allow (a count: Countable, an indexer: ArrayAccess too).
                $implements = $this->collectionInterfacesOf($fromSymbol);
                if ($implements !== null ? !in_array($interface, $implements, true) : (
                    !$fromSymbol->external
                    || !$this->symbols->isSubtypeOf($fromSymbol->fqn, 'Countable')
                    || (in_array($interface, ['IList', 'IReadOnlyList'], true) && !$this->symbols->isSubtypeOf($fromSymbol->fqn, 'ArrayAccess'))
                )) {
                    return false;
                }
            }
            $element = $fromSymbol?->elementType !== null ? $fromSymbol->elementType->substitute($fromSymbol->substitutionFor($from)) : null;

            return $element === null || $this->argumentConverts($element, $to->element(), self::collectionVariance($to));
        }
        if ($to->kind === 'closure') {
            return $fromSymbol === null || strcasecmp($fromSymbol->fqn, 'Closure') === 0;
        }

        // To a primitive or an array: only untyped PHP objects (not the runtime library) might still work.
        return $fromSymbol !== null && $fromSymbol->external && !$fromSymbol->isEnum()
            && !str_starts_with($fromSymbol->fqn, Names::RUNTIME_NAMESPACE . '\\');
    }
}
