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
        if ($this->conversions->isImplicit($value->type, $target, $value->code === '0', $argument !== null)) {
            return;
        }
        $from = $value->type->describe();
        $to = $target->describe();

        throw match (true) {
            $argument !== null => $this->error('SP1503', "Argument $argument: cannot convert from '$from' to '$to'", $at),
            $value->type->isNull() => $this->error('SP0037', "Cannot convert null to '$to' because it is a non-nullable value type", $at),
            $this->conversions->isExplicit($value->type, $target) => $this->error('SP0266', "Cannot implicitly convert type '$from' to '$to'. An explicit conversion exists (are you missing a cast?)", $at),
            default => $this->error('SP0029', "Cannot implicitly convert type '$from' to '$to'", $at),
        };
    }
}
