<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\CodeWriter;
use SharpPhp\CodeGen\Emitted;
use SharpPhp\CodeGen\FunctionContext;
use SharpPhp\CodeGen\LambdaContext;
use SharpPhp\CodeGen\Prec;
use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;
use SharpPhp\Syntax\AnonymousObjectExpr;
use SharpPhp\Syntax\AwaitExpr;
use SharpPhp\Syntax\ArrayCreationExpr;
use SharpPhp\Syntax\AsExpr;
use SharpPhp\Syntax\AssignExpr;
use SharpPhp\Syntax\BaseExpr;
use SharpPhp\Syntax\BinaryExpr;
use SharpPhp\Syntax\Block;
use SharpPhp\Syntax\CastExpr;
use SharpPhp\Syntax\CollectionExpr;
use SharpPhp\Syntax\ConditionalExpr;
use SharpPhp\Syntax\DeclarationExpr;
use SharpPhp\Syntax\DefaultExpr;
use SharpPhp\Syntax\ElementAccessExpr;
use SharpPhp\Syntax\Expr;
use SharpPhp\Syntax\InitializerItem;
use SharpPhp\Syntax\InterpolatedStringExpr;
use SharpPhp\Syntax\InterpolationHole;
use SharpPhp\Syntax\InvocationExpr;
use SharpPhp\Syntax\IsPatternExpr;
use SharpPhp\Syntax\LambdaExpr;
use SharpPhp\Syntax\LiteralExpr;
use SharpPhp\Syntax\MemberAccessExpr;
use SharpPhp\Syntax\NameExpr;
use SharpPhp\Syntax\NameOfExpr;
use SharpPhp\Syntax\NewExpr;
use SharpPhp\Syntax\PredefinedTypeExpr;
use SharpPhp\Syntax\SwitchExpr;
use SharpPhp\Syntax\ThisExpr;
use SharpPhp\Syntax\ThrowExpr;
use SharpPhp\Syntax\TypeOfExpr;
use SharpPhp\Syntax\TypeRef;
use SharpPhp\Syntax\UnaryExpr;
use SharpPhp\Syntax\WithExpr;

/**
 * @internal part of {@see \SharpPhp\CodeGen\Emitter}
 */
trait EmitsExpressions
{
    /** Runtime generic collections that accept an "of:" element type for runtime checks. */
    private const TYPED_COLLECTIONS = ['List_', 'Dictionary', 'HashSet', 'Queue', 'Stack'];

    private function expr(Expr $e, ?Type $expected = null): Emitted
    {
        $result = $this->exprOf($e, $expected);

        // Implicit numeric conversion of char (int i = 'a'; Math.Max(c, 10)).
        if ($expected !== null && $expected->isNumeric() && $result->type->isChar() && !$result->type->nullable) {
            return $this->charToInt($result);
        }

        return $result;
    }

    private function exprOf(Expr $e, ?Type $expected): Emitted
    {
        return match (true) {
            $e instanceof LiteralExpr => $this->literal($e, $expected),
            $e instanceof InterpolatedStringExpr => $this->interpolatedString($e),
            $e instanceof NameExpr => $this->valueOf($this->name($e, 'value'), $e),
            $e instanceof MemberAccessExpr => $this->valueOf($this->memberAccess($e, 'value'), $e),
            $e instanceof InvocationExpr => $this->invocation($e),
            $e instanceof ElementAccessExpr => $this->elementAccess($e),
            $e instanceof UnaryExpr => $this->unary($e),
            $e instanceof BinaryExpr => $this->binary($e, $expected),
            $e instanceof AssignExpr => $this->assignment($e),
            $e instanceof ConditionalExpr => $this->conditional($e, $expected),
            $e instanceof IsPatternExpr => $this->isPatternExpr($e),
            $e instanceof AsExpr => $this->asExpr($e),
            $e instanceof CastExpr => $this->cast($e),
            $e instanceof NewExpr => $this->newExpr($e, $expected),
            $e instanceof AnonymousObjectExpr => $this->anonymousObject($e),
            $e instanceof AwaitExpr => $this->awaitExpr($e),
            $e instanceof ArrayCreationExpr => $this->arrayCreation($e, $expected),
            $e instanceof CollectionExpr => $this->collectionExpr($e, $expected),
            $e instanceof LambdaExpr => $this->lambda($e, $expected),
            $e instanceof ThisExpr => $this->thisExpr($e),
            $e instanceof TypeOfExpr => $this->typeOf($e),
            $e instanceof NameOfExpr => $this->nameOf($e),
            $e instanceof DefaultExpr => $this->defaultValue($e->type !== null ? $this->resolveType($e->type) : $expected),
            $e instanceof SwitchExpr => $this->switchExpr($e, $expected),
            $e instanceof WithExpr => $this->withExpr($e),
            $e instanceof ThrowExpr => Emitted::value('throw ' . $this->expr($e->value)->code, Type::mixed(), Prec::LOWEST),
            $e instanceof BaseExpr => throw $this->error('SP0175', "Use of keyword 'base' is not valid in this context", $e),
            $e instanceof PredefinedTypeExpr => throw $this->error('SP0119', "'{$e->keyword}' is a type, which is not valid in the given context", $e),
            $e instanceof DeclarationExpr => throw $this->error('SP8185', 'A declaration is not allowed in this context', $e),
            default => throw $this->error('SP1010', 'Unsupported expression', $e),
        };
    }

    // ------------------------------------------------------------------
    // Literals and strings
    // ------------------------------------------------------------------

    private function literal(LiteralExpr $e, ?Type $expected): Emitted
    {
        return match ($e->kind) {
            'int' => $expected !== null && $expected->isFloat()
                // An int constant where a float type is wanted: that type's value (16777217 as a float is 16777216).
                ? Emitted::value(self::phpFloat($expected->floatingName() === 'float' ? (float) \SharpPhp\Runtime\Op::single($e->floatValue()) : $e->floatValue()), $expected->floatingName() === Type::UNSIZED ? Type::float() : Type::floating($expected->floatingName()))
                : new Emitted((string) $e->intValue(), Type::integral($e->integral), intValue: $e->intValue()),
            // 1.5 is a double, 1.5f a float (single precision), 1.5m a decimal.
            'float' => Emitted::value(self::phpFloat($e->floating === 'float' ? (float) \SharpPhp\Runtime\Op::single($e->floatValue()) : $e->floatValue()), Type::floating($e->floating)),
            'string' => Emitted::value(self::phpString($e->stringValue()), Type::string()),
            'char' => new Emitted(self::phpString($e->stringValue()), Type::char(), intValue: mb_ord($e->stringValue())),
            'bool' => Emitted::value($e->value ? 'true' : 'false', Type::bool()),
            default => Emitted::value('null', Type::null()),
        };
    }

    private function interpolatedString(InterpolatedStringExpr $e): Emitted
    {
        $parts = [];
        foreach ($e->parts as $part) {
            if (is_string($part)) {
                if ($part !== '') {
                    $parts[] = Emitted::value(self::phpString($part), Type::string());
                }
                continue;
            }
            $parts[] = $this->interpolationHole($part);
        }

        if ($parts === []) {
            return Emitted::value("''", Type::string());
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        $code = [];
        foreach ($parts as $i => $part) {
            $code[] = $this->wrap($part, $i === 0 ? Prec::CONCAT : Prec::CONCAT + 1);
        }

        return Emitted::value(implode(' . ', $code), Type::string(), Prec::CONCAT);
    }

    private function interpolationHole(InterpolationHole $hole): Emitted
    {
        $value = $this->expr($hole->expr);
        $text = $hole->format !== null
            ? Emitted::value($this->runtime('Op') . '::' . ($value->type->kind !== 'float' ? 'format' : match ($value->type->floatingName()) {
                'decimal' => 'formatDecimal',
                'float' => 'formatSingle',
                default => 'format',
            }) . '(' . $value->code . ', ' . self::phpString($hole->format) . ')', Type::string())
            : $this->asString($value);
        if ($hole->alignment === null) {
            return $text;
        }

        return Emitted::value($this->runtime('Op') . '::align(' . $text->code . ', ' . $hole->alignment . ')', Type::string());
    }

    /** Converts a value to a string the way C# would (true => "True", objects => ToString()). */
    /**
     * The type of arithmetic with a double, float or decimal operand, as C#'s numeric promotion: double
     * when one is a double, else float when one is a float, decimal with integers. A decimal with a double
     * or a float is CS0019 (there is no implicit conversion between them).
     */
    private function promotedFloating(Type $l, Type $r, string $op, \SharpPhp\Syntax\Node $at): Type
    {
        $names = [$l->isFloat() ? $l->floatingName() : 'integral', $r->isFloat() ? $r->floatingName() : 'integral'];
        if (in_array('decimal', $names, true) && (in_array('double', $names, true) || in_array('float', $names, true))
            && $l->isNumeric() && $r->isNumeric()) {
            throw $this->error('SP0019', "Operator '$op' cannot be applied to operands of type '{$l->describe()}' and '{$r->describe()}'", $at);
        }
        foreach (['decimal', 'double', Type::UNSIZED, 'float'] as $name) {
            if (in_array($name, $names, true)) {
                return $name === Type::UNSIZED ? Type::float() : Type::floating($name);
            }
        }

        return Type::float();
    }

    /**
     * The kind of operand C#'s operator rules look at, when the compiler knows the type: number, char,
     * bool, string, enum, struct (a value type: DateTime, a struct of the program), class (a class, a
     * record, an array) or null. Null (not checked) for object, dynamic, type parameters, delegates,
     * interfaces, anonymous types and values of PHP code of unknown type.
     */
    private function operandKind(Type $type): ?string
    {
        $plain = $type->withNullable(false);
        if ($plain->kind === 'class' && !$plain->isAnonymous()) {
            $symbol = $this->symbols->symbolForType($plain);

            return match (true) {
                $symbol === null || $symbol->isInterface() || $symbol->kind === 'delegate' => null,
                $symbol->isEnum() => 'enum',
                $symbol->isStruct => 'struct',
                default => 'class',
            };
        }

        return match (true) {
            $plain->isNull() => 'null',
            $plain->isNumeric() => 'number',
            $plain->isChar() => 'char',
            $plain->isBool() => 'bool',
            $plain->isString() => 'string',
            $plain->kind === 'array' && !$plain->isPhpArray() => 'class',
            default => null,
        };
    }

    /**
     * C#'s CS0019: a binary operator that is not defined for its operands (true + 1, "a" * 2, "a" < "b",
     * E1.A == E2.A, date == "x", 5 ?? 0), when the compiler knows both types (see operandKind()).
     * Operators that a class defines (DateTime - DateTime) are found before.
     */
    private function checkBinaryOperands(string $op, Emitted $l, Emitted $r, \SharpPhp\Syntax\Node $at): void
    {
        $a = $this->operandKind($l->type);
        $b = $this->operandKind($r->type);
        if ($op === '??') {
            // The left operand must be able to be null: not an int, a bool, an enum or a struct.
            if (in_array($a, ['number', 'char', 'bool', 'enum', 'struct'], true) && !$l->type->nullable && $b !== null) {
                throw $this->error('SP0019', "Operator '??' cannot be applied to operands of type '{$l->type->describe()}' and '{$r->type->describe()}'", $at);
            }

            return;
        }
        if ($a === null || $b === null || $a === 'null' || $b === 'null') {
            return;
        }
        $numeric = static fn (string $kind): bool => $kind === 'number' || $kind === 'char';
        $integral = static fn (Emitted $e): bool => $e->type->isChar() || $e->type->isInt();
        $sameEnum = $a === 'enum' && $b === 'enum' && $l->type->withNullable(false)->equals($r->type->withNullable(false));
        // The constant 0 converts to every enum type: e == 0, e & 0.
        $enumAndZero = ($a === 'enum' && $r->type->isInt() && $r->intValue === 0) || ($b === 'enum' && $l->type->isInt() && $l->intValue === 0);
        $related = static fn (): bool => false;
        if (($a === 'class' || $a === 'struct') && $a === $b) {
            $plainL = $l->type->withNullable(false);
            $plainR = $r->type->withNullable(false);
            $related = fn (): bool => $plainL->equals($plainR) || $this->conversions->isImplicit($plainL, $plainR) || $this->conversions->isImplicit($plainR, $plainL);
        }
        $ok = match ($op) {
            '+' => $a === 'string' || $b === 'string' || ($numeric($a) && $numeric($b))
                || ($a === 'enum' && $integral($r)) || ($b === 'enum' && $integral($l)),
            '-' => ($numeric($a) && $numeric($b)) || $sameEnum || ($a === 'enum' && $integral($r)),
            '*', '/', '%' => $numeric($a) && $numeric($b),
            // The count of a shift converts to int: not a long, a uint or a ulong.
            '<<', '>>', '>>>' => $integral($l) && $integral($r) && !in_array($r->type->withNullable(false)->integralName(), ['long', 'uint', 'ulong'], true),
            '&', '|', '^' => ($integral($l) && $integral($r)) || ($a === 'bool' && $b === 'bool') || $sameEnum || $enumAndZero,
            '&&', '||' => $a === 'bool' && $b === 'bool' && !$l->type->nullable && !$r->type->nullable,
            // Reference types compare when one converts to the other; a struct needs an == of its own.
            '==', '!=' => ($numeric($a) && $numeric($b)) || ($a === $b && in_array($a, ['bool', 'string'], true))
                || $sameEnum || $enumAndZero || ($a === 'class' && $b === 'class' && $related())
                || ($a === 'struct' && $b === 'struct' && $this->isRecordType($l->type->withNullable(false)) && $related()),
            '<', '>', '<=', '>=' => ($numeric($a) && $numeric($b)) || $sameEnum || $enumAndZero,
            default => true,
        };
        if (!$ok) {
            throw $this->error('SP0019', "Operator '$op' cannot be applied to operands of type '{$l->type->describe()}' and '{$r->type->describe()}'", $at);
        }
    }

    /**
     * C#'s arithmetic of enums: e - E.A is the difference of their values (an int), e + 1, 1 + e and e - 1
     * the enum value that many further (PHP's enums have no operators). Null for other operands.
     */
    private function enumArithmetic(string $op, Emitted $l, Emitted $r): ?Emitted
    {
        if (($op !== '+' && $op !== '-') || $l->type->nullable || $r->type->nullable) {
            return null;
        }
        $leftEnum = $this->conversions->isEnum($l->type);
        $rightEnum = $this->conversions->isEnum($r->type);
        $integral = static fn (Emitted $e): bool => $e->type->isInt() || $e->type->isChar();
        if ($leftEnum && $rightEnum && $op === '-') {
            return $this->infix('-', $this->enumValue($l), $this->enumValue($r), Prec::ADD, Type::int());
        }
        if ($leftEnum !== $rightEnum && ($leftEnum ? $integral($r) : $integral($l) && $op === '+')) {
            [$enum, $number] = $leftEnum ? [$l, $this->charToInt($r)] : [$r, $this->charToInt($l)];
            $sum = $leftEnum ? $this->infix($op, $this->enumValue($enum), $number, Prec::ADD, Type::int()) : $this->infix('+', $number, $this->enumValue($enum), Prec::ADD, Type::int());

            return Emitted::value($this->classRef((string) $enum->type->class) . '::from(' . $sum->code . ')', $enum->type->withNullable(false));
        }

        return null;
    }

    /** C#'s CS0023: a unary operator that is not defined for its operand (-"a", !5, ~1.5, b++ of a bool). */
    private function checkUnaryOperand(string $op, Emitted $operand, \SharpPhp\Syntax\Node $at): void
    {
        $kind = $this->operandKind($operand->type);
        if ($kind === null || $kind === 'null') {
            return;
        }
        $ok = match ($op) {
            '!' => $kind === 'bool',
            '-', '+' => $kind === 'number' || $kind === 'char',
            '~' => ($kind === 'number' && $operand->type->isInt()) || $kind === 'char' || $kind === 'enum',
            '++', '--' => $kind === 'number' || $kind === 'char' || $kind === 'enum',
            default => true,
        };
        if (!$ok) {
            throw $this->error('SP0023', "Operator '$op' cannot be applied to operand of type '{$operand->type->describe()}'", $at);
        }
    }

    /**
     * C#'s lifted operators: with a nullable operand (int?, double?, bool?), arithmetic gives null when an
     * operand is null, and <, >, <=, >= give false (PHP's null + 5 is 5, null < 5 is true). bool?'s & and
     * | are three-valued: null & false is false, null | true is true, else null with a null operand.
     * Null when the operands are not nullable numbers.
     */
    private function liftedOperation(string $op, Emitted $l, Emitted $r, \SharpPhp\Syntax\Node $at): ?Emitted
    {
        $liftable = static fn (Type $t): bool => $t->nullable && ($t->isNumeric() || $t->isBool() || $t->isChar());
        if (!in_array($op, ['+', '-', '*', '/', '%', '&', '|', '^', '<<', '>>', '<', '>', '<=', '>='], true)
            || !($liftable($l->type) || $liftable($r->type)) || $l->type->isString() || $r->type->isString()
            || $l->type->isNull() || $r->type->isNull()) {
            return null;
        }
        $op_ = $this->runtime('Op');
        if (($op === '&' || $op === '|') && $l->type->isBool() && $r->type->isBool()) {
            return Emitted::value("$op_::" . ($op === '&' ? 'boolAnd' : 'boolOr') . "({$l->code}, {$r->code})", Type::bool()->withNullable());
        }
        $comparison = in_array($op, ['<', '>', '<=', '>='], true);
        $simple = static fn (Emitted $e): bool => preg_match('/^(\$[A-Za-z_]\w*|-?\d[\d.eE+-]*)$/', $e->code) === 1;
        if ($simple($l) && $simple($r)) {
            // Inline for variables: ($a === null || $b === null ? null : $a + $b).
            $inner = $this->binaryOperation($op, new Emitted($l->code, $l->type->withNullable(false), Prec::ATOM, intValue: $l->intValue), new Emitted($r->code, $r->type->withNullable(false), Prec::ATOM, intValue: $r->intValue), $at);
            $tests = array_values(array_filter([$l->type->nullable ? $l->code : null, $r->type->nullable ? $r->code : null]));
            if ($comparison) {
                return Emitted::value('(' . implode(' && ', array_map(static fn (string $c): string => "$c !== null", $tests)) . ' && ' . $this->wrap($inner, Prec::AND) . ')', Type::bool());
            }

            return Emitted::value('(' . implode(' || ', array_map(static fn (string $c): string => "$c === null", $tests)) . ' ? null : ' . $this->wrap($inner, Prec::TERNARY + 1) . ')', $inner->type->withNullable());
        }
        if ($this->fn !== null) {
            // Inline, with temporaries: each operand is evaluated once, left to right, and both are
            // evaluated, as in C# (& and | do not short-circuit): (($t1 = a) === null | ($t2 = b) === null
            // ? null : $t1 + $t2).
            $tests = [];
            $plain = [];
            foreach ([$l, $r] as $operand) {
                if (preg_match('/^-?\d[\d.eE+-]*$/', $operand->code) === 1) {
                    $plain[] = new Emitted($operand->code, $operand->type->withNullable(false), Prec::ATOM, intValue: $operand->intValue);
                    continue;
                }
                $temp = $this->currentFunction()->temp();
                $tests[] = "($temp = {$operand->code}) " . ($comparison ? '!==' : '===') . ' null';
                $plain[] = new Emitted($temp, $operand->type->withNullable(false), Prec::ATOM, intValue: $operand->intValue);
            }
            $inner = $this->binaryOperation($op, $plain[0], $plain[1], $at);

            return $comparison
                ? Emitted::value('(' . implode(' & ', $tests) . ' && ' . $this->wrap($inner, Prec::AND) . ')', Type::bool())
                : Emitted::value('(' . implode(' | ', $tests) . ' ? null : ' . $this->wrap($inner, Prec::TERNARY + 1) . ')', $inner->type->withNullable());
        }
        $inner = $this->binaryOperation($op, Emitted::value('$__l', $l->type->withNullable(false)), Emitted::value('$__r', $r->type->withNullable(false)), $at);
        $closure = "static fn (\$__l, \$__r) => {$inner->code}";

        return $comparison
            ? Emitted::value("$op_::liftedTest($closure, {$l->code}, {$r->code})", Type::bool())
            : Emitted::value("$op_::lifted($closure, {$l->code}, {$r->code})", $inner->type->withNullable());
    }

    /** A float (System.Single) result is rounded to single precision, as C# computes float arithmetic. */
    private function singleRounded(Emitted $value): Emitted
    {
        return $value->type->kind === 'float' && $value->type->floatingName() === 'float'
            ? Emitted::value($this->runtime('Op') . "::single({$value->code})", $value->type)
            : $value;
    }

    /** A method named without a call (list.Count where Count() is meant): PHP's first-class callable syntax. */
    private static function isMethodGroup(Emitted $value): bool
    {
        return $value->type->kind === 'closure' && str_ends_with($value->code, '(...)');
    }

    private function asString(Emitted $value): Emitted
    {
        if (($value->type->isString() || $value->type->isChar()) && !$value->type->nullable) {
            return $value;
        }
        if ($value->type->isInt() && !$value->type->nullable) {
            return $value;
        }
        // A double as .NET Core writes it (0.30000000000000004), a float with its single-precision digits,
        // a decimal (a PHP float too) with 15 significant digits and no exponent: 0.1m + 0.2m is 0.3.
        if ($value->type->kind === 'float') {
            $helper = match ($value->type->floatingName()) {
                'float' => 'singleText',
                'decimal' => 'decimalText',
                default => 'doubleText',
            };

            return Emitted::value($this->runtime('Op') . "::$helper({$value->code})", Type::string());
        }
        // An array as text is its type's name, as in .NET ("System.Int32[]"); PHP knows no element type.
        if ($value->type->kind === 'array' && !$value->type->isPhpArray()) {
            return Emitted::value($this->runtime('Op') . "::arrayName({$value->code}, " . self::phpString($this->netTypeName($value->type)) . ')', Type::string());
        }

        return Emitted::value($this->runtime('Op') . '::str(' . $value->code . ')', Type::string());
    }

    /**
     * C#'s best common type: the element type all the others convert to implicitly ({ 1, 2.5 } is double,
     * { 1, 2L } long, { "a", null } string); null when there is none or a type is not known.
     *
     * @param list<Type> $types
     */
    private function bestCommonType(array $types): ?Type
    {
        $known = array_values(array_filter($types, static fn (Type $t): bool => !$t->isNull()));
        if ($known === [] || array_filter($known, static fn (Type $t): bool => $t->isUnknown() || $t->kind === 'closure') !== []) {
            return null;
        }
        foreach ($known as $candidate) {
            if ($candidate->isInt() && $candidate->integralName() === Type::UNSIZED) {
                continue; // an integer of PHP code fits any integral type
            }
            $all = true;
            foreach ($types as $t) {
                if (!$this->conversions->isImplicit($t, $candidate)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return $candidate;
            }
        }

        return null;
    }

    /** The .NET name of a type, as GetType().ToString() gives it: "System.Int32[]", "System.Int64". */
    private function netTypeName(Type $type): string
    {
        $integrals = ['sbyte' => 'SByte', 'byte' => 'Byte', 'short' => 'Int16', 'ushort' => 'UInt16', 'int' => 'Int32', 'uint' => 'UInt32', 'long' => 'Int64', 'ulong' => 'UInt64'];

        return match (true) {
            $type->kind === 'array' => $this->netTypeName($type->element()) . '[]',
            $type->isInt() => 'System.' . ($integrals[$type->integralName()] ?? 'Int32'),
            $type->isChar() => 'System.Char',
            $type->isString() => 'System.String',
            $type->isBool() => 'System.Boolean',
            $type->kind === 'float' => match ($type->floatingName()) {
                'float' => 'System.Single',
                'decimal' => 'System.Decimal',
                default => 'System.Double',
            },
            $type->isClass() => \SharpPhp\Runtime\TypeName::ofClass((string) $type->class),
            default => 'System.Object',
        } . ($type->nullable && $this->conversions->isValueType($type) && $type->kind !== 'array' ? '' : '');
    }

    // ------------------------------------------------------------------
    // Operators
    // ------------------------------------------------------------------

    /** A char used as a number: its UTF-16 code ('A' => 65). Char literals are folded. */
    private function charToInt(Emitted $value): Emitted
    {
        if (!$value->type->isChar()) {
            return $value;
        }
        // A char's code is a ushort (char converts to ushort, int, long ...; arithmetic promotes it to int).
        if ($value->intValue !== null) {
            return new Emitted((string) $value->intValue, Type::integral('ushort'), intValue: $value->intValue);
        }

        return Emitted::value('\\mb_ord(' . $value->code . ')', Type::integral('ushort'));
    }

    /** An enum used as a number: its underlying value (C# compares enums numerically). */
    private function enumValue(Emitted $value): Emitted
    {
        return $this->conversions->isEnum($value->type)
            ? Emitted::value($this->wrap($value, Prec::ATOM) . '->value', Type::int())
            : $value;
    }

    /** An int converted to a char: (char)66 => 'B'. */
    private function intToChar(Emitted $value): Emitted
    {
        $code = $value->type->isInt() ? $value->code : '(int) ' . $this->wrap($value, Prec::UNARY);

        return Emitted::value('\\mb_chr(' . $code . ')', Type::char());
    }

    /** Statement expression whose value is discarded (x++; in a for iterator, ...). */
    private function effect(Expr $e): string
    {
        $outer = $this->discarded;
        $this->discarded = $e;
        try {
            return $this->expr($e)->code;
        } finally {
            $this->discarded = $outer;
        }
    }

    private function unary(UnaryExpr $e): Emitted
    {
        $op = $e->operator;

        if ($op === '++' || $op === '--') {
            $update = $this->collectionElementUpdate($e->operand, $op === '++' ? '+' : '-', null, $e, $e->postfix && $this->discarded !== $e);
            if ($update !== null) {
                return $update;
            }
            $target = $this->place($e->operand);
            if ($e->operand instanceof NameExpr) {
                $this->requireAssigned($e->operand->name, $e->operand);
            }
            $this->checkUnaryOperand($op, $target, $e);
            if ($this->conversions->isEnum($target->type) && !$target->type->nullable && !$target->member?->isStaticAccessor) {
                // e++: the next value of the enum (PHP's enums have no operators).
                $enum = $this->classRef((string) $target->type->class);
                $step = $op === '++' ? '+' : '-';
                $assign = "{$target->code} = $enum::from({$target->code}->value $step 1)";
                if (!$e->postfix || $this->discarded === $e) {
                    return Emitted::value($assign, $target->type, Prec::ASSIGN);
                }
                $back = $op === '++' ? '-' : '+';

                return Emitted::value("$enum::from(($assign)->value $back 1)", $target->type);
            }
            if ($target->member?->isStaticAccessor) {
                $step = $op === '++' ? '+' : '-';
                $setter = $target->receiver . 'set_' . $target->member->name;
                $char = $target->type->isChar();
                if ($e->postfix && $this->discarded !== $e) {
                    // The old value: (set_X(($t = get_X()) + 1) ?? $t)
                    $temp = $this->temp($e);
                    $next = $char ? "\\mb_chr(\\mb_ord($temp = {$target->code}) $step 1)" : "($temp = {$target->code}) $step 1";

                    return Emitted::value("($setter($next) ?? $temp)", $target->type);
                }
                $next = $char ? "\\mb_chr(\\mb_ord({$target->code}) $step 1)" : "{$target->code} $step 1";

                return $this->callStaticSetter($target, Emitted::value($next, $target->type, $char ? Prec::ATOM : Prec::ADD), $e);
            }

            if ($target->type->isChar()) {
                // PHP's string increment ('z'++ === 'aa') is not C#'s ('z' + 1 === '{').
                $step = $op === '++' ? '+' : '-';
                $assign = "{$target->code} = \\mb_chr(\\mb_ord({$target->code}) $step 1)";
                if (!$e->postfix || $this->discarded === $e) {
                    return Emitted::value($assign, Type::char(), Prec::ASSIGN);
                }
                $back = $op === '++' ? '-' : '+';

                return Emitted::value("\\mb_chr(\\mb_ord($assign) $back 1)", Type::char());
            }

            // An int? that is null stays null (C#'s lifted ++); a float keeps single precision.
            $single = $target->type->kind === 'float' && $target->type->floatingName() === 'float';
            if (($target->type->nullable && $target->type->isNumeric()) || $single) {
                $step = $op === '++' ? '+' : '-';
                $one = new Emitted('1', Type::int(), Prec::ATOM, intValue: 1);
                $next = $this->binaryOperation($step, new Emitted($target->code, $target->type, Prec::ATOM), $one, $e);
                $assign = "{$target->code} = " . $this->wrap($next, Prec::ASSIGN);
                if (!$e->postfix || $this->discarded === $e) {
                    return Emitted::value($assign, $target->type, Prec::ASSIGN);
                }
                $temp = $this->temp($e);

                return Emitted::value("(($temp = {$target->code}) === null ? null : (($assign) !== null ? $temp : $temp))", $target->type);
            }

            $narrow = $target->type->isInt() ? $target->type->withNullable(false)->integralName() : null;
            if ($narrow !== null && in_array($narrow, self::NARROW_INTEGRALS, true)) {
                // byte 255 + 1 is 0 in C#: the variable keeps its type's range.
                $step = $op === '++' ? '+' : '-';
                $narrowFn = $this->runtime('Op') . '::narrow';
                $assign = "{$target->code} = $narrowFn({$target->code} $step 1, '$narrow')";
                if (!$e->postfix || $this->discarded === $e) {
                    return Emitted::value($assign, $target->type, Prec::ASSIGN);
                }
                $back = $op === '++' ? '-' : '+';

                return Emitted::value("$narrowFn(($assign) $back 1, '$narrow')", $target->type);
            }

            return Emitted::value($e->postfix ? $target->code . $op : $op . $target->code, $target->type, Prec::UNARY);
        }

        $operand = $this->expr($e->operand, $op === '!' ? Type::bool() : null);
        if ($op === '-' && $operand->type->withNullable(false)->isClass() && ($negation = $this->negationMethod($operand, $e)) !== null) {
            return $negation; // -span: an operator the class defines
        }
        $this->checkUnaryOperand($op, $operand, $e);
        $operand = $op === '!' ? $this->converted($operand, Type::bool(), $e->operand) : $this->charToInt($operand);

        // -5 is a constant too (array sizes, CS0248).
        // -x of a byte or short is an int; of a uint a long (C#'s unary promotion).
        $negated = $operand->type->isInt()
            ? (in_array($operand->type->integralName(), ['uint'], true) ? Type::integral('long') : self::promotedIntegral($operand->type, $operand->type))
            : $operand->type;
        if ($op === '-' && $operand->type->isInt() && $operand->intValue !== null && $operand->intValue !== PHP_INT_MIN) {
            $literal = $operand->type->integralName() === 'long' || $operand->type->integralName() === 'ulong' ? $negated : Type::int();

            return new Emitted('-' . $this->unaryOperand($operand, '-'), $literal, Prec::UNARY, intValue: -$operand->intValue);
        }

        // -x, +x, ~x of an int? (and !b of a bool?) are null when x is null, as in C#.
        if (in_array($op, ['-', '~', '!'], true) && $operand->type->nullable && ($operand->type->isNumeric() || $operand->type->isBool())) {
            $x = $this->fn !== null ? $this->currentFunction()->temp() : '$__x';
            $inner = match ($op) {
                '-' => Emitted::value("-$x", $negated->withNullable(false), Prec::UNARY),
                '~' => Emitted::value("~$x", $negated->withNullable(false), Prec::UNARY),
                default => Emitted::value("!$x", Type::bool(), Prec::NOT),
            };

            return $this->fn !== null
                ? Emitted::value("(($x = {$operand->code}) === null ? null : {$inner->code})", $inner->type->withNullable())
                : Emitted::value($this->runtime('Op') . "::lifted(static fn (\$__x) => {$inner->code}, {$operand->code})", $inner->type->withNullable());
        }

        return match ($op) {
            '-' => Emitted::value('-' . $this->unaryOperand($operand, '-'), $operand->type->isNumeric() ? $negated : Type::mixed(), Prec::UNARY),
            '+' => $operand->type->isInt() ? new Emitted($operand->code, self::promotedIntegral($operand->type, $operand->type), $operand->prec, intValue: $operand->intValue) : $operand,
            '!' => Emitted::value('!' . $this->wrap($operand, Prec::NOT), Type::bool(), Prec::NOT),
            '~' => Emitted::value('~' . $this->unaryOperand($operand, '~'), $operand->type->isInt() ? self::promotedIntegral($operand->type, $operand->type) : Type::int(), Prec::UNARY),
            default => throw $this->error('SP1010', "Unsupported operator '$op'", $e),
        };
    }

    /** Integral types smaller than PHP's int (or unsigned): what C# stores in them wraps around. */
    private const NARROW_INTEGRALS = ['sbyte', 'byte', 'short', 'ushort', 'uint'];

    /**
     * A number cut to an integral type as C# does without overflow checks: (byte)300 is 44, (int)x of a
     * long keeps its low 32 bits. Types of 64 bits (and unknown sizes) are PHP's int already.
     */
    private function narrowed(Emitted $value, Type $target): Emitted
    {
        $name = $target->integralName();
        if (in_array($name, ['long', 'ulong', Type::UNSIZED], true)) {
            return new Emitted($value->code, $target, $value->prec);
        }

        return Emitted::value($this->runtime('Op') . '::narrow(' . $value->code . ", '$name')", $target);
    }

    /**
     * The type of arithmetic on two integers, as C#'s binary numeric promotion: int unless an operand is
     * long (long), ulong (ulong), or uint (uint; long with a signed operand). byte, short, char: int.
     */
    private static function promotedIntegral(Type $a, Type $b): Type
    {
        $names = [$a->kind === 'int' ? $a->integralName() : 'int', $b->kind === 'int' ? $b->integralName() : 'int'];
        if (in_array(Type::UNSIZED, $names, true)) {
            return Type::integral(Type::UNSIZED);
        }

        return match (true) {
            in_array('ulong', $names, true) => Type::integral('ulong'),
            in_array('long', $names, true) => Type::integral('long'),
            in_array('uint', $names, true) => array_diff($names, ['uint', 'byte', 'ushort']) === [] ? Type::integral('uint') : Type::integral('long'),
            default => Type::int(),
        };
    }

    private function unaryOperand(Emitted $operand, string $op): string
    {
        $code = $this->wrap($operand, Prec::UNARY);
        if (str_starts_with($code, '-') || str_starts_with($code, '+')) {
            return '(' . $code . ')';
        }

        return $code;
    }

    private function binary(BinaryExpr $e, ?Type $expected): Emitted
    {
        if ($e->operator === '&&' || $e->operator === '||') {
            $left = $this->expr($e->left, Type::bool());
            $right = $this->expr($e->right, Type::bool());
            $this->checkBinaryOperands($e->operator, $left, $right, $e);

            return $this->binaryOperation($e->operator, $this->converted($left, Type::bool(), $e->left), $this->converted($right, Type::bool(), $e->right), $e);
        }
        $left = $this->expr($e->left);
        $right = $this->expr($e->right, $e->operator === '??' ? $left->type->withNullable(false) : null);

        return $this->binaryOperation($e->operator, $left, $right, $e);
    }

    private function binaryOperation(string $op, Emitted $l, Emitted $r, \SharpPhp\Syntax\Node $at): Emitted
    {
        $operands = [$l, $r]; // as written, for checkBinaryOperands()
        // char is an integral type: 'a' + 1 === 98, c - '0' is a digit's value.
        // Two chars are still compared as strings ($c >= 'a'), which orders them the same way.
        $numberLike = static fn (Type $t): bool => !$t->nullable && ($t->isChar() || $t->isNumeric());
        $comparison = in_array($op, ['==', '!=', '<', '>', '<=', '>='], true);
        if (in_array($op, ['<', '>', '<=', '>='], true)) {
            $l = $this->enumValue($l);
            $r = $this->enumValue($r);
        }
        if (($l->type->isChar() || $r->type->isChar()) && $numberLike($l->type) && $numberLike($r->type)
            && !in_array($op, ['&&', '||', '??'], true) && !($comparison && $l->type->isChar() && $r->type->isChar())) {
            $l = $this->charToInt($l);
            $r = $this->charToInt($r);
        }
        $defined = $this->operatorMethod($op, $l, $r, $at);
        if ($defined !== null) {
            return $defined;
        }
        // a + b, handler -= h: delegates combine (all of them are called) and remove, as Delegate.Combine.
        if (($op === '+' || $op === '-') && ($l->type->kind === 'closure' || $r->type->kind === 'closure')
            && !$l->type->isString() && !$r->type->isString()) {
            $delegate = $this->runtime('System\\Delegate');
            $type = $l->type->kind === 'closure' ? $l->type : $r->type;

            return Emitted::value("$delegate::" . ($op === '+' ? 'Combine' : 'Remove') . "({$l->code}, {$r->code})", $type->withNullable());
        }
        $this->checkBinaryOperands($op, $operands[0], $operands[1], $at);
        $enumArithmetic = $this->enumArithmetic($op, $operands[0], $operands[1]);
        if ($enumArithmetic !== null) {
            return $enumArithmetic;
        }

        $lifted = $this->liftedOperation($op, $l, $r, $at);
        if ($lifted !== null) {
            return $lifted;
        }

        $lt = $l->type;
        $rt = $r->type;
        // C#'s numeric promotion: byte + byte is an int, int + long a long, uint + int a long.
        $numericType = $lt->isFloat() || $rt->isFloat() ? $this->promotedFloating($lt, $rt, $op, $at) : self::promotedIntegral($lt, $rt);
        $bothNumeric = $lt->isNumeric() && $rt->isNumeric();
        $bothInt = $lt->isInt() && $rt->isInt();
        $op_ = $this->runtime('Op');

        switch ($op) {
            case '+':
                if ($lt->isString() || $rt->isString()) {
                    // "n = " + list.Count, where Count is a method (list.Count()): C#'s CS0019, not a delegate's name.
                    $describe = static fn (Emitted $x): string => self::isMethodGroup($x) ? 'method group' : $x->type->describe();
                    if (self::isMethodGroup($l) || self::isMethodGroup($r)) {
                        throw $this->error('SP0019', "Operator '+' cannot be applied to operands of type '{$describe($l)}' and '{$describe($r)}'", $at);
                    }

                    return $this->infix('.', $this->asString($l), $this->asString($r), Prec::CONCAT, Type::string());
                }
                if ($bothNumeric) {
                    return $this->singleRounded($this->infix('+', $l, $r, Prec::ADD, $numericType));
                }

                return Emitted::value("$op_::add({$l->code}, {$r->code})", Type::mixed());
            case '-':
            case '*':
                return $bothNumeric
                    ? $this->singleRounded($this->infix($op, $l, $r, $op === '*' ? Prec::MUL : Prec::ADD, $numericType))
                    : $this->infix($op, $l, $r, $op === '*' ? Prec::MUL : Prec::ADD, Type::mixed());
            case '/':
                if ($bothInt) {
                    return Emitted::value("\\intdiv({$l->code}, {$r->code})", $numericType);
                }
                if ($bothNumeric) {
                    // fdiv: 1.0 / 0 is Infinity (and 0.0 / 0 NaN) as in C#; PHP's "/" throws there.
                    return $this->singleRounded(Emitted::value("\\fdiv({$l->code}, {$r->code})", $numericType));
                }

                return Emitted::value("$op_::div({$l->code}, {$r->code})", Type::mixed());
            case '%':
                if ($bothInt) {
                    return $this->infix('%', $l, $r, Prec::MUL, $numericType);
                }
                if ($bothNumeric) {
                    return $this->singleRounded(Emitted::value("\\fmod({$l->code}, {$r->code})", $numericType));
                }

                return Emitted::value("$op_::mod({$l->code}, {$r->code})", Type::mixed());
            case '<<':
            case '>>':
                // The type of a shift is the left operand's, promoted: byte << 1 is an int, long << 1 a long.
                return $this->infix($op, $l, $r, Prec::SHIFT, self::promotedIntegral($lt, $lt));
            case '&':
            case '|':
            case '^':
                $prec = ['&' => Prec::BAND, '|' => Prec::BOR, '^' => Prec::BXOR][$op];
                if ($this->conversions->isEnum($lt) && $lt->withNullable(false)->equals($rt->withNullable(false)) && !$lt->nullable
                    && $this->symbols->isSubtypeOf((string) $lt->class, Names::RUNTIME_NAMESPACE . '\\FlagsEnum')) {
                    // A [Flags] enum of the runtime library: it has a case for each combination.
                    $enum = $this->classRef((string) $lt->class);

                    return Emitted::value("$enum::from(" . $this->wrap($l, Prec::ATOM) . "->value $op " . $this->wrap($r, Prec::ATOM) . '->value)', $lt);
                }
                if ($this->conversions->isEnum($lt) || $this->conversions->isEnum($rt)) {
                    // PHP failed at run time ("Unsupported operand types"): an enum value is an object.
                    throw $this->error('SP1010', "Combining enum values with '$op' ([Flags] enums) is not supported: SharpPHP's enums are PHP enums, which have no combined values; combine their numbers instead: (int)A | (int)B", $at);
                }
                if ($lt->isBool() && $rt->isBool()) {
                    return Emitted::value('(bool) (' . $this->infix($op, $l, $r, $prec, Type::int())->code . ')', Type::bool(), Prec::UNARY);
                }

                return $this->infix($op, $l, $r, $prec, $bothInt ? $numericType : Type::int());
            case '==':
            case '!=':
                return $this->equality($op === '==', $l, $r);
            case '<':
            case '>':
            case '<=':
            case '>=':
                return $this->infix($op, $l, $r, Prec::REL, Type::bool());
            case '&&':
                return $this->infix('&&', $l, $r, Prec::AND, Type::bool());
            case '||':
                return $this->infix('||', $l, $r, Prec::OR, Type::bool());
            case '??':
                $type = match (true) {
                    $lt->isNull() => $rt,
                    str_starts_with($r->code, 'throw ') => $lt->withNullable(false),
                    default => $this->unify($lt->withNullable(false), $rt),
                };

                return Emitted::value($this->wrap($l, Prec::COALESCE + 1) . ' ?? ' . $this->wrap($r, Prec::COALESCE), $type, Prec::COALESCE);
        }

        throw $this->error('SP1010', "Unsupported operator '$op'", $at);
    }

    /**
     * An operator that a class of the runtime library defines with a static method tagged
     * "@sharp-operator" (MemberSymbol::$operator): end - start is DateTime::op_Subtraction($end, $start),
     * a TimeSpan, and a < b is DateTime::Compare($a, $b) < 0. Only for two operands of class types, so
     * text + date stays a concatenation and date == null the default equality.
     *
     * With a nullable operand (DateTime?) the operator is C#'s lifted one: == and != compare the values
     * (Op::equals), <, >, <= and >= are false when a side is null, + and - give null.
     *
     * Null when no class of the operands defines the operator; C#'s CS0019 when one does, but not for
     * these operand types.
     */
    private function operatorMethod(string $op, Emitted $l, Emitted $r, \SharpPhp\Syntax\Node $at): ?Emitted
    {
        $kind = match ($op) {
            '+', '-' => $op,
            '<', '>', '<=', '>=', '==', '!=' => 'compare',
            default => null,
        };
        $lt = $l->type->withNullable(false);
        $rt = $r->type->withNullable(false);
        if ($kind === null || !$lt->isClass() || !$rt->isClass()) {
            return null;
        }
        $defined = false;
        foreach ([$lt, $rt] as $operand) {
            foreach ($this->symbolForType($operand)->members ?? [] as $member) {
                if ($member->operator !== $kind || count($member->params) !== 2) {
                    continue;
                }
                $defined = true;
                if ($this->conversions->isImplicit($lt, $member->params[0]->type) && $this->conversions->isImplicit($rt, $member->params[1]->type)) {
                    return $l->type->nullable || $r->type->nullable
                        ? $this->liftedOperator($op, $member, $l, $r)
                        : $this->operatorCall($op, $member, $l, $r);
                }
            }
        }
        if ($defined) {
            throw $this->error('SP0019', "Operator '$op' cannot be applied to operands of type '{$l->type->describe()}' and '{$r->type->describe()}'", $at);
        }

        return null;
    }

    /** Class::op_Addition($l, $r), or Class::Compare($l, $r) <op> 0 for a comparison. */
    private function operatorCall(string $op, MemberSymbol $member, Emitted $l, Emitted $r): Emitted
    {
        $call = $this->classRef($member->owner->fqn) . '::' . $member->name . "({$l->code}, {$r->code})";
        if ($member->operator !== 'compare') {
            return Emitted::value($call, $member->type);
        }

        return Emitted::value("$call $op 0", Type::bool(), $op === '==' || $op === '!=' ? Prec::EQ : Prec::REL);
    }

    /** The lifted operator of operatorMethod(), for operands that can be null. */
    private function liftedOperator(string $op, MemberSymbol $member, Emitted $l, Emitted $r): Emitted
    {
        $op_ = $this->runtime('Op');
        $method = $this->classRef($member->owner->fqn) . '::' . $member->name . '(...)';
        if ($op === '==' || $op === '!=') {
            $equals = "$op_::equals({$l->code}, {$r->code})";

            return $op === '==' ? Emitted::value($equals, Type::bool()) : Emitted::value("!$equals", Type::bool(), Prec::UNARY);
        }
        if ($member->operator === 'compare') {
            return Emitted::value("$op_::liftedCompare({$l->code}, {$r->code}, $method, '$op')", Type::bool());
        }

        return Emitted::value("$op_::lifted($method, {$l->code}, {$r->code})", $member->type->withNullable());
    }

    /**
     * -span: the negation a class of the runtime library defines with a one-parameter
     * "@sharp-operator -" method (TimeSpan::op_UnaryNegation), lifted for a nullable operand.
     * C#'s CS0023 for a project class and for a runtime class with other operators but not this
     * one (-date); null for other PHP classes, which may overload operators themselves (GMP).
     */
    private function negationMethod(Emitted $operand, UnaryExpr $e): ?Emitted
    {
        $symbol = $this->symbolForType($operand->type->withNullable(false));
        if ($symbol === null) {
            return null;
        }
        $hasOperators = false;
        foreach ($symbol->members as $member) {
            $hasOperators = $hasOperators || $member->operator !== null;
            if ($member->operator === '-' && count($member->params) === 1) {
                $method = $this->classRef($member->owner->fqn) . '::' . $member->name;

                return $operand->type->nullable
                    ? Emitted::value($this->runtime('Op') . "::lifted($method(...), {$operand->code})", $member->type->withNullable())
                    : Emitted::value("$method({$operand->code})", $member->type);
            }
        }
        if ($hasOperators || !$symbol->external) {
            throw $this->error('SP0023', "Operator '-' cannot be applied to operand of type '{$operand->type->describe()}'", $e);
        }

        return null;
    }

    private function infix(string $op, Emitted $l, Emitted $r, int $prec, Type $type): Emitted
    {
        $nonAssoc = in_array($prec, Prec::NON_ASSOCIATIVE, true);
        $left = $this->wrap($l, $nonAssoc ? $prec + 1 : $prec);
        $right = $this->wrap($r, $prec + 1);
        if (($op === '-' || $op === '+') && (str_starts_with($right, '-') || str_starts_with($right, '+'))) {
            $right = '(' . $right . ')';
        }

        return Emitted::value("$left $op $right", $type, $prec);
    }

    private function equality(bool $equal, Emitted $l, Emitted $r): Emitted
    {
        $lt = $l->type;
        $rt = $r->type;
        $loose = ($lt->isInt() && $rt->isFloat()) || ($lt->isFloat() && $rt->isInt())
            || $this->isRecordType($lt) || $this->isRecordType($rt);
        $op = $loose ? ($equal ? '==' : '!=') : ($equal ? '===' : '!==');

        return $this->infix($op, $l, $r, Prec::EQ, Type::bool());
    }

    private function isRecordType(Type $type): bool
    {
        $symbol = $this->symbolForType($type);

        return $symbol !== null && $symbol->isRecord;
    }

    private function assignment(AssignExpr $e): Emitted
    {
        // order?.Total = 5 (C# 14): assigns, and evaluates 5, only when order is not null; the value is null otherwise.
        if ($e->target instanceof MemberAccessExpr && $e->target->nullConditional) {
            $receiver = $this->expr($e->target->target);
            $temp = $this->currentFunction()->temp('target');
            $this->scope->declare(substr($temp, 1), $receiver->type->withNullable(false));
            $plain = (new MemberAccessExpr((new NameExpr(substr($temp, 1), []))->at($e->target->target), $e->target->name, false, $e->target->typeArgs))->at($e->target);
            $assigned = $this->assignment((new AssignExpr($e->operator, $plain, $e->value))->at($e));

            return Emitted::value("(($temp = {$receiver->code}) === null ? null : " . $this->wrap($assigned, Prec::TERNARY + 1) . ')', $assigned->type->withNullable());
        }
        if ($e->operator !== '=' && $e->operator !== '??=') {
            $update = $this->collectionElementUpdate($e->target, substr($e->operator, 0, -1), $e->value, $e);
            if ($update !== null) {
                return $update;
            }
        }
        // button.Clicked += handler: an event allows += and -= from outside its class.
        $this->eventTargetOfAddOrRemove = $e->operator === '+=' || $e->operator === '-=';
        try {
            $target = $this->place($e->target);
        } finally {
            $this->eventTargetOfAddOrRemove = false;
        }
        $type = $target->type;
        $op = $e->operator;

        if ($target->member?->isStaticAccessor) {
            if ($op === '??=') {
                // Only a null value is replaced: Type::get_X() ?? Type::set_X(value)
                $set = $this->callStaticSetter($target, $this->convert($e->value, $type), $e);

                return Emitted::value($target->code . ' ?? ' . $this->wrap($set, Prec::COALESCE), $type, Prec::COALESCE);
            }
            $value = $op === '='
                ? $this->convert($e->value, $type)
                : $this->binaryOperation(substr($op, 0, -1), $target, $this->expr($e->value), $e);
            if ($type->isChar() && !$value->type->isChar() && !$value->type->isString()) {
                $value = $this->intToChar($value);
            }

            return $this->callStaticSetter($target, $value, $e);
        }

        if ($op !== '=' && $e->target instanceof NameExpr) {
            $this->requireAssigned($e->target->name, $e->target);
        }
        if ($op === '=') {
            $value = $this->convert($e->value, $type);
            $this->markAssigned($e->target);

            return Emitted::value($target->code . ' = ' . $this->wrap($value, Prec::ASSIGN), $type->isUnknown() ? $value->type : $type, Prec::ASSIGN);
        }
        if ($op === '??=') {
            $value = $this->convert($e->value, $type);

            return Emitted::value($target->code . ' ??= ' . $this->wrap($value, Prec::ASSIGN), $type, Prec::ASSIGN);
        }

        $binaryOp = substr($op, 0, -1);
        // Clicked += () => ...: the lambda gets the delegate's parameter types.
        $value = $this->expr($e->value, $type->kind === 'closure' ? $type->withNullable(false) : null);
        $vt = $value->type;

        if ($type->isChar() && !$vt->isString()) {
            // c += 2 => $c = \mb_chr(\mb_ord($c) + 2)
            $combined = $this->intToChar($this->binaryOperation($binaryOp, $target, $value, $e));

            return Emitted::value($target->code . ' = ' . $combined->code, $type, Prec::ASSIGN);
        }
        if ($vt->isChar() && !$type->isString()) {
            $value = $this->charToInt($value);
            $vt = $value->type;
        }
        // byte b += 10: C# computes in int and stores the result cut to the variable's type (b = (byte)(b + 10)),
        // when the value converts to that type (b += 1000 is CS0031, b += anInt CS0266).
        $narrow = $type->isInt() ? $type->withNullable(false)->integralName() : null;
        if ($narrow !== null && in_array($narrow, self::NARROW_INTEGRALS, true) && $vt->isInt()) {
            if ($binaryOp !== '<<' && $binaryOp !== '>>') {
                $this->converted($value, $type->withNullable(false), $e->value);
            }
            $combined = $this->binaryOperation($binaryOp, $target, $value, $e);

            return Emitted::value($target->code . ' = ' . $this->runtime('Op') . "::narrow({$combined->code}, '$narrow')", $type, Prec::ASSIGN);
        }
        $bothNumeric = $type->isNumeric() && $vt->isNumeric();
        $bothInt = $type->isInt() && $vt->isInt();
        // i += 1.5, i += aLong: the value must convert to the variable's type (CS0266), as C# stores the
        // result without a cast; a constant that fits it does (ul += 1).
        if ($bothNumeric && $binaryOp !== '<<' && $binaryOp !== '>>') {
            [$min, $max] = $type->isInt() ? Type::INTEGRAL_RANGES[$type->withNullable(false)->integralName()] ?? [PHP_INT_MIN, PHP_INT_MAX] : [0, -1];
            $constantFits = $vt->isInt() && $value->intValue !== null && $type->isInt() && $value->intValue >= $min && $value->intValue <= $max;
            if (!$constantFits && !$this->conversions->isImplicit($vt, $type)) {
                throw $this->conversionError($value, $type, $e->value, null);
            }
        }
        // n += 5 of an int? that is null stays null (lifted); f += x of a float keeps single precision.
        if ((($type->nullable || $vt->nullable) && $bothNumeric) || ($type->nullable && $type->isBool())
            || ($type->kind === 'float' && $type->floatingName() === 'float' && $bothNumeric)) {
            $combined = $this->binaryOperation($binaryOp, $target, $value, $e);

            return Emitted::value($target->code . ' = ' . $this->wrap($combined, Prec::ASSIGN), $type, Prec::ASSIGN);
        }

        // Use PHP's compound operator when it has the same meaning as the C# one (s -= "b" is still CS0019;
        // class operands go through binaryOperation(), which finds the operators classes define first).
        if (!$type->isClass() && !$vt->isClass()) {
            $this->checkBinaryOperands($binaryOp, $target, $value, $e);
        }
        $compound = match ($binaryOp) {
            '+' => $type->isString() || $vt->isString() ? '.=' : ($bothNumeric ? '+=' : null),
            '/' => null, // intdiv() or fdiv(), never PHP's "/"
            '%' => $bothInt ? '%=' : null,
            // Objects have no PHP operators: date -= span goes through binaryOperation() (operatorMethod()).
            '-', '*', '<<', '>>', '&', '|', '^' => $type->isClass() || $vt->isClass() || $type->kind === 'closure' || $vt->kind === 'closure' ? null : $binaryOp . '=',
            default => null,
        };
        if ($compound !== null) {
            $right = $compound === '.=' ? $this->asString($value) : $value;

            return Emitted::value($target->code . " $compound " . $this->wrap($right, Prec::ASSIGN), $type, Prec::ASSIGN);
        }

        $combined = $this->binaryOperation($binaryOp, $target, $value, $e);

        return Emitted::value($target->code . ' = ' . $this->wrap($combined, Prec::ASSIGN), $type, Prec::ASSIGN);
    }

    /**
     * Assignment to a static property with accessors: Type::set_X(value). Used as a value,
     * the assignment yields the assigned value: (Type::set_X($t = value) ?? $t).
     */
    private function callStaticSetter(Emitted $target, Emitted $value, Expr $at): Emitted
    {
        $setter = $target->receiver . 'set_' . $target->member?->name;
        if ($this->discarded === $at) {
            return Emitted::value("$setter({$value->code})", Type::void());
        }
        $temp = $this->temp($at);

        return Emitted::value("($setter($temp = {$value->code}) ?? $temp)", $target->type);
    }

    /**
     * dict[key] += value, list[i]++: compiled to Op::update() for collection objects, whose elements
     * PHP cannot modify in place. Null for any other target (arrays and strings work natively).
     *
     * @param ?Expr $value the right operand, or null for ++/-- (an operand of 1)
     */
    private function collectionElementUpdate(Expr $target, string $operator, ?Expr $value, Expr $at, bool $returnOld = false): ?Emitted
    {
        if (!$target instanceof ElementAccessExpr || $target->nullConditional || count($target->indexes) !== 1) {
            return null;
        }
        $receiver = $this->expr($target->target);
        $symbol = $this->symbolForType($receiver->type);
        $offsetGet = $symbol !== null ? $this->findMethod($symbol, 'offsetGet') : null;
        if ($symbol === null || $offsetGet === null) {
            return null;
        }
        $subst = $symbol->substitutionFor($receiver->type);
        $keyType = isset($offsetGet->params[0]) ? $offsetGet->params[0]->type->substitute($subst) : Type::mixed();
        $elementType = $offsetGet->type->substitute($subst);
        $key = $this->convert($target->indexes[0], $keyType);

        $oldName = $this->temp($at);
        $old = Emitted::value($oldName, $elementType);
        $new = $this->binaryOperation($operator, $old, $value !== null ? $this->expr($value) : Emitted::value('1', Type::int()), $at);
        $narrow = $elementType->isInt() ? $elementType->withNullable(false)->integralName() : null;
        if ($narrow !== null && in_array($narrow, self::NARROW_INTEGRALS, true) && $new->type->isInt()) {
            $new = $this->narrowed($new, $elementType->withNullable(false)); // bytes[0] += 250 wraps, as in C#
        }
        if ($elementType->isChar() && !$new->type->isString()) {
            $new = $this->intToChar($new);
        }
        $code = $this->runtime('Op') . "::update({$this->wrap($receiver, Prec::ATOM)}, {$key->code}, fn ($oldName) => {$new->code}"
            . ($returnOld ? ', returnOld: true' : '') . ')';

        return Emitted::value($code, $elementType);
    }

    /** An expression used as an assignment target (variable, property, indexer). */
    private function place(Expr $e): Emitted
    {
        $result = match (true) {
            $e instanceof NameExpr => $this->valueOf($this->name($e, 'assign'), $e),
            $e instanceof MemberAccessExpr => $this->valueOf($this->memberAccess($e, 'assign'), $e),
            $e instanceof ElementAccessExpr => $this->elementAccess($e, true),
            default => null,
        };
        if ($result?->member?->extensionProperty !== null) {
            throw $this->error('SP0200', "Property or indexer '{$result->member->owner->csharpName()}.{$result->member->extensionProperty}' cannot be assigned to -- it is read only", $e);
        }
        if ($result?->member?->isStaticAccessor) {
            $this->requireWritable($result->member, $e);

            return $result;
        }
        if ($result === null || preg_match('/^\$[\w\x80-\xff]+$|->[\w\x80-\xff]+$|::\$[\w\x80-\xff]+$|\]$/', $result->code) !== 1) {
            throw $this->error('SP0131', 'The left-hand side of an assignment must be a variable, property or indexer', $e);
        }
        if ($result->member !== null) {
            $this->requireWritable($result->member, $e);
        }

        return $result;
    }

    private function conditional(ConditionalExpr $e, ?Type $expected): Emitted
    {
        $condition = $this->convert($e->condition, Type::bool());
        $then = $this->expr($e->then, $expected);
        $else = $this->expr($e->else, $expected ?? $then->type);
        if ($expected === null) {
            $this->checkConditionalType($then, $else, $e);
        }

        return Emitted::value(
            $this->wrap($condition, Prec::TERNARY + 1) . ' ? ' . $this->wrap($then, Prec::TERNARY + 1) . ' : ' . $this->wrap($else, Prec::TERNARY + 1),
            $this->unify($then->type, $else->type),
            Prec::TERNARY,
        );
    }

    /**
     * C#'s CS0173: without a target type (var v = c ? 1 : "a"), a conditional expression takes the type
     * one branch converts to; there must be one. With a target type (object o = c ? 1 : "a") C# 9 converts
     * each branch to it instead. Only branches whose types are known (see operandKind()) are checked.
     */
    private function checkConditionalType(Emitted $then, Emitted $else, \SharpPhp\Syntax\Node $at): void
    {
        $a = $this->operandKind($then->type);
        $b = $this->operandKind($else->type);
        if ($a === null || $b === null || ($a === 'null' && $b === 'null')) {
            return;
        }
        if ($a === 'null' || $b === 'null') {
            // null and a value type have no type in common (int? is the target type's business).
            $other = $a === 'null' ? $else : $then;
            $ok = $other->type->nullable || in_array($this->operandKind($other->type), ['string', 'class'], true);
        } else {
            $ok = $this->conversions->isImplicit($then->type, $else->type, $then->type->isInt() && $then->intValue === 0)
                || $this->conversions->isImplicit($else->type, $then->type, $else->type->isInt() && $else->intValue === 0);
        }
        if (!$ok) {
            throw $this->error('SP0173', "Type of conditional expression cannot be determined because there is no implicit conversion between '{$then->type->describe()}' and '{$else->type->describe()}'", $at);
        }
    }

    private function unify(Type $a, Type $b): Type
    {
        if ($a->equals($b)) {
            return $a;
        }
        if ($a->isNull()) {
            return $b->withNullable();
        }
        if ($b->isNull()) {
            return $a->withNullable();
        }
        if ($a->isInt() && $b->isInt()) {
            // cond ? aByte : anInt is an int: the type both convert to.
            $plainA = $a->withNullable(false);
            $plainB = $b->withNullable(false);
            $wider = $this->conversions->isImplicit($plainA, $plainB) ? $plainB : ($this->conversions->isImplicit($plainB, $plainA) ? $plainA : Type::integral('long'));

            return $wider->withNullable($a->nullable || $b->nullable);
        }
        if ($a->isNumeric() && $b->isNumeric()) {
            return Type::float();
        }
        if ($a->withNullable(false)->equals($b->withNullable(false))) {
            return $a->withNullable();
        }

        return Type::mixed();
    }

    // ------------------------------------------------------------------
    // Type operations
    // ------------------------------------------------------------------

    private function asExpr(AsExpr $e): Emitted
    {
        $value = $this->expr($e->expr);
        $type = $this->resolveType($e->type);

        return Emitted::value(
            $this->runtime('Op') . '::as(' . $value->code . ', ' . $this->typeTestOperand($type) . ')',
            $type->withNullable(),
        );
    }

    /** "Foo::class" for classes, a quoted spec for primitives. */
    private function typeTestOperand(Type $type): string
    {
        return $type->isClass() ? $this->classRef((string) $type->class) . '::class' : self::phpString($type->runtimeSpec());
    }

    private function cast(CastExpr $e): Emitted
    {
        $target = $this->resolveType($e->type);
        $value = $this->expr($e->expr, $target);
        $source = $value->type;
        $sourceSymbol = $this->symbolForType($source);

        // Unboxing (object -> int, ...) is checked at runtime like in C#.
        if ($source->isUnknown() && in_array($target->kind, ['int', 'float', 'bool', 'char'], true)) {
            return Emitted::value($this->runtime('Op') . '::cast(' . $value->code . ', ' . self::phpString($target->runtimeSpec()) . ')', $target);
        }

        switch ($target->kind) {
            case 'int':
                $plain = $target->withNullable(false);
                if ($source->isInt() || $source->isChar()) {
                    $number = $this->charToInt($value);
                    // (byte)300 as a constant: C#'s CS0221; (byte)x wraps at run time (unchecked).
                    if ($number->intValue !== null) {
                        [$min, $max] = Type::INTEGRAL_RANGES[$plain->integralName()] ?? [PHP_INT_MIN, PHP_INT_MAX];
                        if ($number->intValue < $min || $number->intValue > $max) {
                            throw $this->error('SP0221', "Constant value '{$number->intValue}' cannot be converted to a '{$plain->describe()}' (use 'unchecked' syntax to override)", $e);
                        }

                        return new Emitted($number->code, $target, $number->prec, intValue: $number->intValue);
                    }
                    if ($this->conversions->isImplicit($number->type->withNullable(false), $plain)) {
                        return new Emitted($number->code, $target, $number->prec);
                    }

                    return $this->narrowed($number, $plain);
                }
                if ($sourceSymbol !== null && $sourceSymbol->isEnum()) {
                    return $this->narrowed(Emitted::value($this->wrap($value, Prec::ATOM) . '->value', Type::int()), $plain);
                }

                // A constant: (int)3.9 is the constant 3; out of the type's range it is C#'s CS0221.
                if ($source->kind === 'float' && is_numeric($value->code)) {
                    $constant = (float) $value->code;
                    [$min, $max] = Type::INTEGRAL_RANGES[$plain->integralName()] ?? [PHP_INT_MIN, PHP_INT_MAX];
                    $whole = $constant < 0 ? ceil($constant) : floor($constant);
                    if ($whole < $min || $whole > $max || !is_finite($constant)) {
                        throw $this->error('SP0221', "Constant value '" . \SharpPhp\Runtime\Op::shortest($constant) . "' cannot be converted to a '{$plain->describe()}' (use 'unchecked' syntax to override)", $e);
                    }
                    $folded = (int) $whole;

                    return new Emitted((string) $folded, $target, $folded < 0 ? Prec::UNARY : Prec::ATOM, intValue: $folded);
                }
                // (int)3.9 is 3; out of range a double saturates (.NET 9): (int)3e10 is int.MaxValue,
                // (uint)-1.0 is 0. byte, short, ... go through int: (byte)300.7 is 44.
                $name = $plain->integralName();
                $via = in_array($name, ['long', 'ulong', 'uint'], true) ? $name : 'int';
                $truncated = Emitted::value($this->runtime('Op') . '::truncate(' . $value->code . ", '$via')", $target);

                return in_array($name, self::NARROW_INTEGRALS, true) && $name !== 'uint'
                    ? $this->narrowed($truncated, $plain) : $truncated;
            case 'float':
                // (float)x is rounded to single precision; (double)f and (decimal)d keep the value.
                $plainTarget = $target->withNullable(false);
                if ($source->isFloat() && $source->floatingName() === $plainTarget->floatingName()) {
                    return new Emitted($value->code, $target, $value->prec);
                }
                $double = $source->isFloat() ? $value : Emitted::value('(float) ' . $this->wrap($this->charToInt($value), Prec::UNARY), Type::float(), Prec::UNARY);

                return $plainTarget->floatingName() === 'float'
                    ? Emitted::value($this->runtime('Op') . "::single({$double->code})", $target)
                    : new Emitted($double->code, $target, $double->prec);
            case 'bool':
                return $source->isBool() ? $value : Emitted::value('(bool) ' . $this->wrap($value, Prec::UNARY), Type::bool(), Prec::UNARY);
            case 'char':
                return $source->isChar() ? $value : $this->intToChar($this->charToInt($value));
            case 'string':
                // A reference conversion, as in C#: null stays null, an object must hold a string
                // (InvalidCastException otherwise); numbers and other types have none (CS0030).
                if ($source->isString() || $source->isNull()) {
                    return new Emitted($value->code, $target, $value->prec);
                }
                if ($source->isUnknown() || $source->kind === 'typeparam' || ($sourceSymbol !== null && $sourceSymbol->external && !self::isRuntimeLibrary($sourceSymbol))) {
                    return Emitted::value($this->runtime('Op') . '::cast(' . $value->code . ", 'string')", $target);
                }
                throw $this->error('SP0030', "Cannot convert type '{$source->describe()}' to 'string'", $e);
            case 'class':
                $symbol = $this->symbols->find((string) $target->class);
                if ($symbol !== null && $symbol->isEnum() && $source->isNumeric()) {
                    return Emitted::value($this->classRef($symbol->fqn) . '::from(' . $value->code . ')', $target);
                }
                if ($source->isClass() && $this->symbols->isSubtypeOf((string) $source->class, (string) $target->class)) {
                    return Emitted::value($value->code, $target, $value->prec);
                }

                return Emitted::value($this->runtime('Op') . '::cast(' . $value->code . ', ' . $this->typeTestOperand($target) . ')', $target);
            default:
                return Emitted::value($value->code, $target, $value->prec);
        }
    }

    /** .NET names of the C# keyword types, for typeof(long).Name == "Int64". */
    private const KEYWORD_TYPE_NAMES = [
        'int' => 'Int32', 'long' => 'Int64', 'short' => 'Int16', 'byte' => 'Byte', 'sbyte' => 'SByte',
        'uint' => 'UInt32', 'ulong' => 'UInt64', 'ushort' => 'UInt16', 'float' => 'Single', 'double' => 'Double',
        'decimal' => 'Decimal', 'bool' => 'Boolean', 'char' => 'Char', 'string' => 'String', 'object' => 'Object',
    ];

    private function typeOf(TypeOfExpr $e): Emitted
    {
        $type = $this->resolveType($e->type);
        $typeClass = $this->runtime('System\\Type');
        $code = $type->isClass()
            ? "$typeClass::ofClass(" . $this->classRef((string) $type->class) . '::class)'
            : "$typeClass::named(" . self::phpString($this->dotNetName($e->type, $type)) . ')';

        return Emitted::value($code, self::systemType());
    }

    /** "System.Int64" for long, "System.Int32[]" for int[], the parameter name for T. */
    private function dotNetName(TypeRef $ref, Type $type): string
    {
        return match (true) {
            $ref->name === '[]' => $this->dotNetName($ref->args[0], $type->element()) . '[]',
            isset(self::KEYWORD_TYPE_NAMES[$ref->name]) => 'System.' . self::KEYWORD_TYPE_NAMES[$ref->name],
            $type->kind === 'typeparam' => (string) $type->param,
            $type->isClass() => Names::csharpName((string) $type->class),
            default => \SharpPhp\Runtime\TypeName::ofSpec($type->withNullable(false)->runtimeSpec()),
        };
    }

    private static function systemType(): Type
    {
        return Type::class(Names::RUNTIME_NAMESPACE . '\\System\\Type');
    }

    /** record with { Name = value }: a copy with some members changed (the constructor does not run). */
    private function withExpr(WithExpr $e): Emitted
    {
        $target = $this->expr($e->target);
        $symbol = $this->symbolForType($target->type);
        if ($symbol === null && !$target->type->isUnknown() || $symbol !== null && !$symbol->isRecord && !$symbol->isStruct) {
            throw $this->error('SP8858', "The receiver type '{$target->type->describe()}' is not a valid record type and is not a struct type.", $e);
        }

        $pairs = [];
        foreach ($e->initializer->items as $item) {
            $memberType = Type::mixed();
            if ($symbol !== null) {
                $member = $this->symbols->findMember($symbol, (string) $item->name);
                if ($member === null || !$member->isValue()) {
                    throw $this->error('SP0117', "'{$symbol->csharpName()}' does not contain a definition for '{$item->name}'", $item);
                }
                $this->requireAccessible($member, $item);
                $this->requireWritable($member, $item, initializer: true);
                $memberType = $member->type->substitute($member->owner->substitutionFor($target->type));
            }
            $pairs[] = self::phpString((string) $item->name) . ' => ' . $this->convert($item->values[0], $memberType)->code;
        }

        return Emitted::value($this->runtime('Op') . "::with({$target->code}, [" . implode(', ', $pairs) . '])', $target->type->withNullable(false));
    }

    private function nameOf(NameOfExpr $e): Emitted
    {
        $name = match (true) {
            $e->expr instanceof NameExpr => $e->expr->name,
            $e->expr instanceof MemberAccessExpr => $e->expr->name,
            $e->expr instanceof PredefinedTypeExpr => $e->expr->keyword,
            default => throw $this->error('SP8081', 'Expression does not have a name', $e),
        };
        if ($e->expr instanceof NameExpr) {
            $this->name($e->expr, 'nameof'); // validates that the name exists
        }

        return Emitted::value(self::phpString($name), Type::string());
    }

    /**
     * default(T): 0, 0.0, false, '\0'; an enum's member of value 0 (PHP enums hold only their members, so
     * an enum without one gives null); new T() for a struct of the runtime library (DateTime.MinValue,
     * TimeSpan.Zero); null for every other type, and for every nullable one (default(int?)).
     */
    private function defaultValue(?Type $type): Emitted
    {
        if ($type !== null && $type->nullable) {
            return Emitted::value('null', $type);
        }
        $symbol = $type !== null && $type->isClass() ? $this->symbolForType($type) : null;
        if ($type !== null && $symbol !== null && $symbol->isEnum() && $this->isIntBackedEnum($symbol)) {
            return Emitted::value($this->classRef($symbol->fqn) . '::tryFrom(0)', $type);
        }
        if ($type !== null && $symbol !== null && $symbol->isStruct && $symbol->external) {
            // A runtime struct's default value: Guid.Empty, TimeSpan.Zero, DateTime.MinValue.
            foreach (['Empty', 'Zero', 'MinValue'] as $name) {
                $member = $symbol->members[$name] ?? null;
                if ($member !== null && $member->isStatic && $member->kind === 'property') {
                    return Emitted::value($this->classRef($symbol->fqn) . '::$' . $name, $type);
                }
            }

            return Emitted::value('new ' . $this->classRef($symbol->fqn) . '()', $type);
        }
        if ($type !== null && $symbol !== null && $symbol->isStruct && !$symbol->external) {
            // default(Point) of a struct: every field at its default, no constructor run.
            return Emitted::value($this->runtime('Op') . '::defaultStruct(' . $this->classRef($symbol->fqn) . '::class)', $type);
        }

        return match ($type?->kind) {
            'int' => Emitted::value('0', Type::int()),
            'float' => Emitted::value('0.0', Type::float()),
            'bool' => Emitted::value('false', Type::bool()),
            'char' => Emitted::value('"\\0"', Type::char()),
            default => Emitted::value('null', $type?->withNullable() ?? Type::null()),
        };
    }

    /** Project enums compile to int-backed PHP enums; a PHP library's enum may be backed by strings, or not at all. */
    private function isIntBackedEnum(TypeSymbol $symbol): bool
    {
        if (!$symbol->external) {
            return true;
        }

        return enum_exists($symbol->fqn) && (string) (new \ReflectionEnum($symbol->fqn))->getBackingType() === 'int';
    }

    private function thisExpr(ThisExpr $e): Emitted
    {
        if ($this->class === null || $this->fn === null || $this->fn->isStatic) {
            throw $this->error('SP0026', "Keyword 'this' is not valid in a static property, static method, or static field initializer", $e);
        }

        return Emitted::value('$this', Type::class($this->class->fqn, array_values($this->class->typeParamTypes)));
    }

    // ------------------------------------------------------------------
    // Object creation
    // ------------------------------------------------------------------

    private function newExpr(NewExpr $e, ?Type $expected): Emitted
    {
        // new string('-', 5), new string(chars): C#'s string constructors.
        if ($e->type !== null && in_array($e->type->name, ['string', 'String', 'System.String'], true) && $e->initializer === null) {
            $string = $this->runtime('System\\String_');
            [$code, $values] = $this->arguments($e->args, [], runtime: true);
            $shapes = array_map(static fn (Emitted $v): string => $v->type->isChar() || $v->type->isString() ? 'char' : ($v->type->kind === 'array' ? 'array' : ($v->type->isInt() ? 'int' : '?')), $values);
            if (!in_array(implode(',', $shapes), ['char,int', 'array', 'array,int,int'], true)) {
                throw $this->error('SP1729', "'string' does not contain a constructor that takes these arguments (use new string(char c, int count) or new string(char[] value))", $e);
            }

            return Emitted::value("$string::__new($code)", Type::string());
        }
        // new object(): a plain object (a lock, a sentinel, a key of a ConditionalWeakTable).
        if ($e->type !== null && in_array($e->type->name, ['object', 'Object', 'System.Object'], true) && $e->type->args === []) {
            if ($e->args !== [] || $e->initializer !== null) {
                throw $this->error('SP1729', "'object' does not contain a constructor that takes " . count($e->args) . ' arguments', $e);
            }

            return Emitted::value('new \\stdClass()', Type::mixed());
        }
        $type = $e->type !== null ? $this->resolveType($e->type) : $expected?->withNullable(false);
        // new Transform(Twice), new Action(() => ...): a delegate made from a method or a lambda.
        if ($type !== null && $type->kind === 'closure' && $e->initializer === null) {
            if (count($e->args) !== 1 || $e->args[0]->name !== null || $e->args[0]->modifier !== null) {
                throw $this->error('SP0149', 'Method name expected', $e);
            }

            return $this->convert($e->args[0]->value, $type);
        }
        if ($type === null || !$type->isClass()) {
            throw $this->error('SP8754', $type === null
                ? "There is no target type for 'new()'"
                : "Cannot create an instance of '{$type->describe()}'", $e);
        }
        $symbol = $this->symbols->find((string) $type->class);
        if ($symbol === null) {
            throw $this->error('SP0246', "The type '{$type->describe()}' could not be found", $e);
        }
        if ($symbol->isInterface() || $symbol->isAbstract) {
            throw $this->error('SP0144', "Cannot create an instance of the abstract type or interface '{$symbol->csharpName()}'", $e);
        }
        if ($symbol->isEnum()) {
            throw $this->error('SP0144', "Cannot create an instance of the enum type '{$symbol->csharpName()}'", $e);
        }

        $class = $this->classRef($symbol->fqn);
        $inaccessible = $this->access->constructorViolation($symbol, $this->class);
        if ($inaccessible !== null) {
            throw $this->violation($inaccessible, $e);
        }
        $ctorParams = $this->symbols->constructorParams($symbol);
        if ($ctorParams !== null) {
            $this->recordCall($e->argumentsLine, $e->argumentsColumn, $e->args, $symbol->shortName(), $ctorParams, null, $symbol->substitutionFor($type));
        }
        if ($symbol->isStruct && $e->args === [] && $ctorParams !== null && $ctorParams !== [] && !$ctorParams[0]->optional) {
            throw $this->error('SP1010', "Structs are classes in SharpPHP, so 'new {$symbol->csharpName()}()' has no implicit parameterless constructor; pass the constructor's arguments", $e);
        }
        if ($ctorParams !== null) {
            $name = $symbol->csharpName();
            $this->checkArguments($e->args, $ctorParams, $name, $this->signature("$name.{$symbol->shortName()}", $ctorParams), $e, constructor: true, runtime: self::isRuntimeLibrary($symbol));
        }
        [$args] = $this->arguments($e->args, $ctorParams ?? [], $symbol->substitutionFor($type), runtime: $symbol->external, phpCode: self::isPhpCode($symbol));
        $of = $this->collectionElementSpec($type, $symbol);
        $items = $e->initializer->items ?? [];
        $elementType = $this->elementTypeOf($type);

        // new List<int> { 1, 2, 3 }  =>  new List_([1, 2, 3], of: 'int')
        if ($items !== [] && $args === '' && $of !== null && $symbol->fqn !== Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\Dictionary'
            && array_reduce($items, static fn (bool $c, InitializerItem $i): bool => $c && $i->kind === 'element', true)) {
            $elements = array_map(fn (InitializerItem $i): string => $this->convert($i->values[0], $elementType)->code, $items);

            return Emitted::value("new $class([" . implode(', ', $elements) . "], of: $of)", $type);
        }

        // (A callback: array_filter() alone would drop the argument list "0", new Num(0).)
        $argList = implode(', ', array_filter([$args, $of !== null ? "of: $of" : ''], static fn (string $part): bool => $part !== ''));
        $code = "new $class($argList)";
        if ($items === [] || $e->initializer === null) {
            return Emitted::value($code, $type);
        }

        return $this->initialized($code, $type, $symbol, $e->initializer);
    }

    /**
     * $code (a new object, or a member's value for a nested initializer) with the initializer applied:
     * Op::init(obj, [members]), Op::collect(list, [elements]), Op::addPairs / Op::setIndexes (dictionaries).
     */
    private function initialized(string $code, Type $type, \SharpPhp\Semantics\TypeSymbol $symbol, \SharpPhp\Syntax\Initializer $initializer): Emitted
    {
        $items = $initializer->items;
        $elementType = $this->elementTypeOf($type);
        $kinds = array_unique(array_map(static fn (InitializerItem $i): string => $i->kind, $items));
        if (count($kinds) > 1) {
            throw $this->error('SP0747', 'Invalid initializer member declarator: object and collection initializers cannot be mixed', $initializer);
        }
        $op = $this->runtime('Op');
        $kind = $kinds[0];

        if ($kind === 'member') {
            $pairs = [];
            $nested = [];
            foreach ($items as $item) {
                $member = $this->symbols->findMember($symbol, (string) $item->name);
                if ($member === null && !$symbol->external) {
                    throw $this->error('SP0117', "'{$symbol->csharpName()}' does not contain a definition for '{$item->name}'", $item);
                }
                if ($member !== null) {
                    $this->requireAccessible($member, $item);
                    if ($item->nested === null) {
                        $this->requireWritable($member, $item, initializer: true);
                    }
                }
                $memberType = $member !== null ? $member->type->substitute($member->owner->substitutionFor($type)) : Type::mixed();
                if ($item->nested !== null) {
                    // Items = { "a", "b" } adds to the list the member already holds; Address = { City = "X" }
                    // sets members of the object it holds. The member is read, not assigned.
                    $memberSymbol = $this->symbolForType($memberType);
                    if ($memberSymbol === null) {
                        throw $this->error('SP1918', "Members of '{$item->name}' cannot be initialized with an object or collection initializer: its type '{$memberType->describe()}' is not a class", $item);
                    }
                    if ($item->nested->items === []) {
                        continue; // Items = { } does nothing
                    }
                    $value = $this->fn !== null ? $this->fn->temp('member') : '$__member';
                    $applied = $this->initialized($value, $memberType, $memberSymbol, $item->nested);
                    $nested[] = self::phpString((string) $item->name) . " => fn ($value) => {$applied->code}";
                    continue;
                }
                $pairs[] = self::phpString((string) $item->name) . ' => ' . $this->convert($item->values[0], $memberType)->code;
            }

            return Emitted::value("$op::init($code, [" . implode(', ', $pairs) . ']' . ($nested !== [] ? ', [' . implode(', ', $nested) . ']' : '') . ')', $type);
        }

        $keyType = $type->args[0] ?? Type::mixed();
        $valueType = $type->args[1] ?? Type::mixed();
        $entries = [];
        foreach ($items as $item) {
            $entries[] = match ($kind) {
                'element' => $this->convert($item->values[0], $elementType)->code,
                'pair' => '[' . implode(', ', array_map(
                    fn (Expr $v, int $i): string => $this->expr($v, $i === 0 ? $keyType : $valueType)->code,
                    $item->values,
                    array_keys($item->values),
                )) . ']',
                'index' => '[' . $this->convert($item->key ?? throw new \LogicException('An index initializer has a key'), $keyType)->code . ', ' . $this->convert($item->values[0], $valueType)->code . ']',
                default => throw new \LogicException("Unexpected initializer kind '$kind'"),
            };
        }
        $helper = ['element' => 'collect', 'pair' => 'addPairs', 'index' => 'setIndexes'][$kind];

        return Emitted::value("$op::$helper($code, [" . implode(', ', $entries) . '])', $type);
    }

    /** "of:" argument for runtime-checked generic collections, or null. */
    private function collectionElementSpec(Type $type, \SharpPhp\Semantics\TypeSymbol $symbol): ?string
    {
        $prefix = Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\';
        if (!str_starts_with($symbol->fqn, $prefix) || !in_array(substr($symbol->fqn, strlen($prefix)), self::TYPED_COLLECTIONS, true) || $type->args === []) {
            return null;
        }
        $specs = array_map(static fn (Type $t): string => self::phpString($t->runtimeSpec()), $type->args);

        return count($specs) === 1 ? $specs[0] : '[' . implode(', ', $specs) . ']';
    }

    private function arrayCreation(ArrayCreationExpr $e, ?Type $expected): Emitted
    {
        $elementType = $e->elementType !== null
            ? $this->resolveType($e->elementType)
            : ($expected !== null && $expected->kind === 'array' ? $expected->element() : null);

        $size = $e->size !== null ? $this->convert($e->size, Type::int()) : null;
        if ($size?->intValue !== null && $size->intValue < 0) {
            throw $this->error('SP0248', 'Cannot create an array with a negative size', $e->size ?? $e);
        }
        if ($e->elements === null) {
            $size ?? throw new \LogicException('An array without elements has a size');
            $default = $this->defaultValue($elementType);
            // .NET throws OverflowException for a negative size computed at run time.
            $count = $size->intValue !== null ? $size->code : $this->runtime('Op') . "::arraySize({$size->code})";

            return Emitted::value("\\array_fill(0, $count, {$default->code})", Type::array($elementType ?? Type::mixed()));
        }
        // new string[2] { "a", "b", "c" }: C# requires as many elements as the size says.
        if ($size?->intValue !== null && $size->intValue !== count($e->elements)) {
            throw $this->error('SP0847', "An array initializer of length '{$size->intValue}' is expected", $e);
        }

        $elements = [];
        if ($elementType === null && count($e->elements) > 1) {
            // new[] { 1, 2L }: the best common type of the elements, as in C# (long here), each element
            // evaluated once and then converted to it.
            $values = array_map(fn (Expr $element): Emitted => $this->expr($element), $e->elements);
            $best = $this->bestCommonType(array_map(static fn (Emitted $v): Type => $v->type, $values));
            foreach ($values as $i => $value) {
                $elements[] = $best === null ? $value->code : $this->converted($value, $best, $e->elements[$i])->code;
            }

            return Emitted::value('[' . implode(', ', $elements) . ']', Type::array($best ?? $values[0]->type));
        }
        foreach ($e->elements as $element) {
            $value = $elementType !== null ? $this->convert($element, $elementType) : $this->expr($element);
            $elementType ??= $value->type;
            $elements[] = $value->code;
        }

        return Emitted::value('[' . implode(', ', $elements) . ']', Type::array($elementType ?? Type::mixed()));
    }

    private function collectionExpr(CollectionExpr $e, ?Type $expected): Emitted
    {
        $symbol = $expected !== null ? $this->symbolForType($expected) : null;
        $elementType = $expected !== null ? $this->elementTypeOf($expected) : null;
        $elements = [];
        foreach ($e->elements as $element) {
            $value = $this->expr($element, $elementType);
            $elementType ??= $value->type;
            $elements[] = $value->code;
        }
        $array = '[' . implode(', ', $elements) . ']';

        if ($symbol !== null && $expected->isClass()) {
            $of = $this->collectionElementSpec($expected, $symbol);

            return Emitted::value('new ' . $this->classRef($symbol->fqn) . "($array" . ($of !== null ? ", of: $of" : '') . ')', $expected);
        }

        return Emitted::value($array, Type::array($elementType ?? Type::mixed()));
    }

    /** await task => Task::await($task): the T of Task<T> (void for Task). */
    private function awaitExpr(AwaitExpr $e): Emitted
    {
        if ($this->fn === null || !$this->fn->isAsync) {
            throw $this->error('SP4034', "The 'await' operator can only be used within an async lambda expression. Consider marking this lambda expression with the 'async' modifier.", $e);
        }
        $task = $this->expr($e->operand);
        $type = $task->type;
        $result = match (true) {
            $type->isUnknown() => Type::mixed(),
            $type->kind === 'class' && strcasecmp((string) $type->class, self::TASK_CLASS) === 0 => $type->args[0] ?? Type::void(),
            $type->kind === 'void' => throw $this->error('SP4008', "Cannot await 'void'", $e),
            default => throw $this->error('SP1061', "'{$type->describe()}' does not contain a definition for 'GetAwaiter' (only tasks can be awaited)", $e),
        };

        return Emitted::value($this->classRef(self::TASK_CLASS) . '::await(' . $task->code . ')', $result);
    }

    /** new { Id = 1, name }: a runtime AnonymousObject; its static type knows the properties and their types. */
    private function anonymousObject(AnonymousObjectExpr $e): Emitted
    {
        $properties = [];
        $items = [];
        $numbers = [];
        foreach ($e->names as $i => $name) {
            $node = $e->values[$i];
            if ($node instanceof LambdaExpr) {
                throw $this->error('SP0828', 'Cannot assign lambda expression to anonymous type property', $node);
            }
            $value = $this->expr($node);
            if ($value->type->isNull() || $value->type->kind === 'void') {
                $what = $value->type->isNull() ? '<null>' : 'void';
                throw $this->error('SP0828', "Cannot assign '$what' to anonymous type property", $node);
            }
            $properties[$name] = $value->type;
            $items[] = self::phpString($name) . ' => ' . $value->code;
            if ($value->type->kind === 'float') {
                // ToString() writes a double, float or decimal as its type does: the runtime is told which.
                $numbers[] = self::phpString($name) . ' => ' . self::phpString(match ($value->type->floatingName()) {
                    'float' => 'float',
                    'decimal' => 'decimal',
                    default => 'double',
                });
            }
        }

        return Emitted::value(
            'new ' . $this->runtime('AnonymousObject') . '([' . implode(', ', $items) . ']' . ($numbers === [] ? '' : ', [' . implode(', ', $numbers) . ']') . ')',
            $this->symbols->anonymousType($properties),
        );
    }

    // ------------------------------------------------------------------
    // Lambdas
    // ------------------------------------------------------------------

    private function lambda(LambdaExpr $e, ?Type $expected): Emitted
    {
        $context = new LambdaContext();
        $expectedParams = $expected !== null && $expected->kind === 'closure' ? array_slice($expected->args, 0, -1) : [];
        $expectedReturn = $expected !== null && $expected->kind === 'closure' && $expected->args !== [] ? $expected->closureReturn() : null;
        // async () => ...: the body returns the T of the Task<T> the delegate returns.
        if ($e->isAsync && $expectedReturn !== null) {
            $expectedReturn = $expectedReturn->isUnknown() ? null : ($this->asyncResultType($expectedReturn)
                ?? throw $this->error('SP4010', "Cannot convert async lambda expression to delegate type returning '{$expectedReturn->describe()}'", $e));
        }

        $outer = $this->flow->save();
        $this->flow->restore($outer ?? []); // a lambda in unreachable code is still checked
        try {
            return $this->lambdaInScope($e, $context, $expectedParams, $expectedReturn);
        } finally {
            $this->flow->restore($outer); // a lambda may never run: its assignments do not count
        }
    }

    /**
     * @param list<Type> $expectedParams
     */
    private function lambdaInScope(LambdaExpr $e, LambdaContext $context, array $expectedParams, ?Type $expectedReturn): Emitted
    {
        return $this->inScope(function () use ($e, $context, $expectedParams, $expectedReturn): Emitted {
            $params = [];
            $paramTypes = [];
            foreach ($e->params as $i => $param) {
                $type = $param->type !== null ? $this->resolveType($param->type) : ($expectedParams[$i] ?? Type::mixed());
                $decl = $param->type !== null ? $this->signatureTypeDecl($type) . ' ' : '';
                $ref = in_array($param->modifier, ['ref', 'out'], true) ? '&' : '';
                $params[] = $decl . $ref . '$' . $param->name;
                $paramTypes[] = $type;
                $this->declareLocal($param->name, $type, $param);
            }
            $paramCode = implode(', ', $params);

            $outerFn = $this->fn;
            $static = $e->isStatic || $this->class === null || $outerFn === null || $outerFn->isStatic;
            $function = new FunctionContext($static, $expectedReturn ?? Type::mixed(), $outerFn->typeParams ?? [], $outerFn);
            $function->isAsync = $e->isAsync;
            $task = $this->classRef(self::TASK_CLASS);

            if ($e->body instanceof Block) {
                $outerWriter = $this->w;
                $this->w = new CodeWriter();
                $this->w->sourceLine = $outerWriter->sourceLine;
                $this->w->indent();
                if ($e->isAsync) {
                    $this->w->indent(); // the body goes inside Task::async(function () { ... })
                }
                try {
                    $this->inFunction($function, function () use ($e): void {
                        $this->statements($e->body->statements);
                    });
                    if ($this->flow->isReachable() && $expectedReturn !== null && !$expectedReturn->isUnknown() && $expectedReturn->kind !== 'void') {
                        $delegate = $this->delegateName(Type::closure([...$paramTypes, $expectedReturn]));
                        throw $this->error('SP1643', "Not all code paths return a value in lambda expression of type '$delegate'", $e);
                    }
                    $body = $this->w->embedded();
                } finally {
                    $this->w = $outerWriter;
                }
                if ($e->isAsync) {
                    // The task runs the body: function (x) use (...) { return Task::async(function () use (x, ...) { body }); }
                    $use = $this->useClause($context, array_map(static fn ($p): string => $p->name, $e->params));
                    $body = '    return ' . $task . '::async(' . ($static ? 'static ' : '') . 'function ()' . $use . " {\n" . $body . "\n    });";
                }
                $code = 'function (' . $paramCode . ')' . $this->useClause($context) . " {\n" . $body . "\n}";
                $returnType = $expectedReturn ?? Type::mixed();
            } else {
                $value = $this->inFunction($function, fn (): Emitted => $this->expr($e->body, $expectedReturn));
                $returnType = $expectedReturn !== null && !$expectedReturn->isUnknown() ? $expectedReturn : $value->type;
                $byReference = in_array(true, $context->captures, true);
                $valueCode = $e->isAsync ? $task . '::async(' . ($static ? 'static ' : '') . 'fn () => ' . $value->code . ')' : $value->code;
                $code = $byReference
                    ? 'function (' . $paramCode . ')' . $this->useClause($context) . " {\n    return " . $valueCode . ";\n}"
                    : 'fn (' . $paramCode . ') => ' . $valueCode;
            }
            if ($e->isStatic) {
                $code = 'static ' . $code;
            }
            if ($e->isAsync) {
                $returnType = Type::class(self::TASK_CLASS, $returnType->kind === 'void' ? [] : [$returnType]);
            }

            return Emitted::value($code, Type::closure([...$paramTypes, $returnType]), Prec::LOWEST);
        }, $context);
    }

    /** @param list<string> $alsoByValue more variables to capture (an async lambda's parameters) */
    private function useClause(LambdaContext $context, array $alsoByValue = []): string
    {
        $vars = array_map(static fn (string $name): string => '$' . $name, $alsoByValue);
        foreach ($context->captures as $name => $byRef) {
            $vars[] = ($byRef ? '&$' : '$') . $name;
        }
        if ($vars === []) {
            return '';
        }

        return ' use (' . implode(', ', $vars) . ')';
    }
}
