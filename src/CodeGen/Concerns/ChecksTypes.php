<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\Semantics\Type;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\Node;

/**
 * Implicit conversion checks (int x = "text"; if (count) ...; Sq("abc")).
 *
 * The compiler does not know every type (object, dynamic, unconstrained T, untyped PHP code),
 * so a conversion is rejected only when both types are known and C# would never allow it.
 *
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait ChecksTypes
{
    /** Emits $e for a slot of type $target, rejecting values C# would not implicitly convert. */
    private function convert(Expr $e, Type $target, ?int $argument = null): Emitted
    {
        $value = $this->expr($e, $target);
        $this->requireConvertible($value, $target, $e, $argument);

        return $value;
    }

    /** @param ?int $argument 1-based argument position, for "Argument 2: cannot convert ..." */
    private function requireConvertible(Emitted $value, Type $target, Node $at, ?int $argument = null): void
    {
        if ($this->convertible($value, $target, $argument !== null)) {
            return;
        }
        $from = $value->type->describe();
        $to = $target->describe();

        if ($argument !== null) {
            throw $this->error('SP1503', "Argument $argument: cannot convert from '$from' to '$to'", $at);
        }
        if ($value->type->isNull()) {
            throw $this->error('SP0037', "Cannot convert null to '$to' because it is a non-nullable value type", $at);
        }
        if ($this->explicitConversionExists($value->type, $target)) {
            throw $this->error('SP0266', "Cannot implicitly convert type '$from' to '$to'. An explicit conversion exists (are you missing a cast?)", $at);
        }

        throw $this->error('SP0029', "Cannot implicitly convert type '$from' to '$to'", $at);
    }

    private function convertible(Emitted $value, Type $to, bool $argument): bool
    {
        $from = $value->type;
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

        return match ($from->kind) {
            'int' => in_array($to->kind, ['int', 'float'], true) || ($value->code === '0' && $this->isEnumType($to)),
            'float', 'bool', 'array' => $to->kind === $from->kind || ($from->kind === 'array' && $to->kind === 'iterable'),
            // Runtime string methods are declared with string parameters, but C# has char overloads.
            'char' => in_array($to->kind, ['char', 'int', 'float'], true) || ($argument && $to->isString()),
            'string' => in_array($to->kind, ['string', 'iterable'], true),
            'closure' => $to->kind === 'closure' || ($to->isClass() && strcasecmp((string) $to->class, 'Closure') === 0),
            'iterable' => !$this->isPrimitive($to),
            'class' => $this->classConvertible($from, $to),
            default => true,
        };
    }

    private function classConvertible(Type $from, Type $to): bool
    {
        $fromSymbol = $this->symbolForType($from);
        if ($to->isClass()) {
            $toSymbol = $this->symbolForType($to);
            if ($fromSymbol === null || $toSymbol === null) {
                return true;
            }

            return $this->symbols->isSubtypeOf($fromSymbol->fqn, $toSymbol->fqn);
        }
        if ($to->kind === 'iterable') {
            return $fromSymbol === null || $fromSymbol->external;
        }
        if ($to->kind === 'closure') {
            return $fromSymbol === null || strcasecmp($fromSymbol->fqn, 'Closure') === 0;
        }

        // To a primitive or an array: only untyped PHP objects might still work.
        return $fromSymbol !== null && $fromSymbol->external && !$fromSymbol->isEnum()
            && !str_starts_with($fromSymbol->fqn, \SharpPhp\Semantics\Names::RUNTIME_NAMESPACE . '\\');
    }

    /** Casts C# accepts where an implicit conversion is not allowed (double -> int, Base -> Derived). */
    private function explicitConversionExists(Type $from, Type $to): bool
    {
        $numeric = static fn (Type $t): bool => in_array($t->kind, ['int', 'float', 'char'], true);
        if ($numeric($from) && $numeric($to)) {
            return true;
        }
        if (($from->isInt() && $this->isEnumType($to)) || ($this->isEnumType($from) && $to->isInt())) {
            return true;
        }
        if ($from->isClass() && $to->isClass()) {
            return $this->symbols->isSubtypeOf((string) $to->class, (string) $from->class);
        }

        return false;
    }

    private function isValueType(Type $type): bool
    {
        return $type->isValueType() || $this->isEnumType($type);
    }

    private function isPrimitive(Type $type): bool
    {
        return in_array($type->kind, ['int', 'float', 'bool', 'char', 'string'], true);
    }

    private function isEnumType(Type $type): bool
    {
        $symbol = $this->symbolForType($type);

        return $symbol !== null && $symbol->isEnum();
    }
}
