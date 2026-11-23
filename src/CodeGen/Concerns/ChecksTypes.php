<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\Emitted;
use SharpPhp\Semantics\Type;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\Node;

/**
 * Reports values that do not convert to the type of the slot they are stored in
 * (int x = "text"; if (count) ...; Sq("abc")). The rules are in {@see \SharpPhp\Semantics\Conversions}.
 *
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait ChecksTypes
{
    /**
     * Emits $e for a slot of type $target, rejecting values C# would not implicitly convert.
     *
     * @param ?int $argument 1-based argument position, for "Argument 2: cannot convert ..."
     * @param bool $runtimeParameter the slot is a parameter of the runtime library, whose string
     *                               parameters stand for C#'s char overloads too
     */
    private function convert(Expr $e, Type $target, ?int $argument = null, bool $runtimeParameter = false): Emitted
    {
        return $this->converted($this->expr($e, $target), $target, $e, $argument, $runtimeParameter);
    }

    /** Like convert(), for a value that has already been emitted. */
    private function converted(Emitted $value, Type $target, Node $at, ?int $argument = null, bool $runtimeParameter = false): Emitted
    {
        $isZero = $value->type->isInt() && $value->intValue === 0;
        if (!$this->conversions->isImplicit($value->type, $target, $isZero, $runtimeParameter)) {
            throw $this->conversionError($value, $target, $at, $argument);
        }
        // Color c = 0;  =>  Color::from(0)
        if ($isZero && $this->conversions->isEnum($target)) {
            return Emitted::value($this->classRef((string) $target->class) . '::from(0)', $target->withNullable(false));
        }
        if ($this->conversions->isPhpArrayBoundary($value->type, $target)) {
            return $this->convertedAtPhpBoundary($value, $target);
        }
        // A string is an IEnumerable<char> ("abc".SequenceEqual("abc"), new HashSet<char>(word)).
        if ($value->type->isString() && $target->kind === 'iterable') {
            return Emitted::value($this->runtime('System\\String_') . '::chars(' . $value->code . ')', Type::array(Type::char())->withNullable($value->type->nullable));
        }

        return $value;
    }

    /**
     * List / Dictionary / HashSet <=> PHP array:
     *   PHP function taking an array  <=  Interop::toArray($list)
     *   Dictionary<string, int> d = phpArray  =>  Interop::toDictionary($array, ['string', 'int'])
     */
    private function convertedAtPhpBoundary(Emitted $value, Type $target): Emitted
    {
        $interop = $this->runtime('Interop');
        if ($target->isPhpArray()) {
            return Emitted::value("$interop::toArray({$value->code})", $target->withNullable($value->type->nullable));
        }
        $name = (string) $this->conversions->collectionName($target, exact: true);
        $symbol = $this->symbols->symbolForType($target);
        $of = $symbol !== null ? $this->collectionElementSpec($target, $symbol) : null;

        return Emitted::value("$interop::to$name({$value->code}" . ($of !== null ? ", $of" : '') . ')', $target->withNullable($value->type->nullable));
    }

    private function conversionError(Emitted $value, Type $target, Node $at, ?int $argument): \SharpPhp\Compiler\CompileError
    {
        $from = $value->type->describe();
        $to = $target->describe();

        return match (true) {
            $argument !== null => $this->error('SP1503', "Argument $argument: cannot convert from '$from' to '$to'", $at),
            $value->type->isNull() => $this->error('SP0037', "Cannot convert null to '$to' because it is a non-nullable value type", $at),
            $this->conversions->isExplicit($value->type, $target) => $this->error('SP0266', "Cannot implicitly convert type '$from' to '$to'. An explicit conversion exists (are you missing a cast?)", $at),
            default => $this->error('SP0029', "Cannot implicitly convert type '$from' to '$to'", $at),
        };
    }
}
