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
                ? Emitted::value(self::phpFloat($e->floatValue()), Type::float())
                : new Emitted((string) $e->intValue(), Type::integral($e->integral), intValue: $e->intValue()),
            'float' => Emitted::value(self::phpFloat($e->floatValue()), Type::float()),
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
            ? Emitted::value($this->runtime('Op') . '::format(' . $value->code . ', ' . self::phpString($hole->format) . ')', Type::string())
            : $this->asString($value);
        if ($hole->alignment === null) {
            return $text;
        }

        return Emitted::value($this->runtime('Op') . '::align(' . $text->code . ', ' . $hole->alignment . ')', Type::string());
    }

    /** Converts a value to a string the way C# would (true => "True", objects => ToString()). */
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

        return Emitted::value($this->runtime('Op') . '::str(' . $value->code . ')', Type::string());
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

        $operand = $op === '!' ? $this->convert($e->operand, Type::bool()) : $this->charToInt($this->expr($e->operand));

        if ($op === '-' && $operand->type->withNullable(false)->isClass() && ($negation = $this->negationMethod($operand, $e)) !== null) {
            return $negation;
        }

        // -5 is a constant too (array sizes, CS0248).
        // -x of a byte or short is an int; of a uint a long (C#'s unary promotion).
        $negated = $operand->type->isInt()
            ? (in_array($operand->type->integralName(), ['uint'], true) ? Type::integral('long') : self::promotedIntegral($operand->type, $operand->type))
            : $operand->type;
        if ($op === '-' && $operand->type->isInt() && $operand->intValue !== null && $operand->intValue !== PHP_INT_MIN) {
            $literal = $operand->type->integralName() === 'long' || $operand->type->integralName() === 'ulong' ? $negated : Type::int();

            return new Emitted('-' . $this->unaryOperand($operand, '-'), $literal, Prec::UNARY, intValue: -$operand->intValue);
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
            return $this->binaryOperation($e->operator, $this->convert($e->left, Type::bool()), $this->convert($e->right, Type::bool()), $e);
        }
        $left = $this->expr($e->left);
        $right = $this->expr($e->right, $e->operator === '??' ? $left->type->withNullable(false) : null);

        return $this->binaryOperation($e->operator, $left, $right, $e);
    }

    private function binaryOperation(string $op, Emitted $l, Emitted $r, \SharpPhp\Syntax\Node $at): Emitted
    {
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

        $lt = $l->type;
        $rt = $r->type;
        // C#'s numeric promotion: byte + byte is an int, int + long a long, uint + int a long.
        $numericType = $lt->isFloat() || $rt->isFloat() ? Type::float() : self::promotedIntegral($lt, $rt);
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
                    return $this->infix('+', $l, $r, Prec::ADD, $numericType);
                }

                return Emitted::value("$op_::add({$l->code}, {$r->code})", Type::mixed());
            case '-':
            case '*':
                return $this->infix($op, $l, $r, $op === '*' ? Prec::MUL : Prec::ADD, $bothNumeric ? $numericType : Type::mixed());
            case '/':
                if ($bothInt) {
                    return Emitted::value("\\intdiv({$l->code}, {$r->code})", $numericType);
                }
                if ($bothNumeric) {
                    // fdiv: 1.0 / 0 is Infinity (and 0.0 / 0 NaN) as in C#; PHP's "/" throws there.
                    return Emitted::value("\\fdiv({$l->code}, {$r->code})", Type::float());
                }

                return Emitted::value("$op_::div({$l->code}, {$r->code})", Type::mixed());
            case '%':
                if ($bothInt) {
                    return $this->infix('%', $l, $r, Prec::MUL, $numericType);
                }
                if ($bothNumeric) {
                    return Emitted::value("\\fmod({$l->code}, {$r->code})", Type::float());
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

        // Use PHP's compound operator when it has the same meaning as the C# one.
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

        return Emitted::value(
            $this->wrap($condition, Prec::TERNARY + 1) . ' ? ' . $this->wrap($then, Prec::TERNARY + 1) . ' : ' . $this->wrap($else, Prec::TERNARY + 1),
            $this->unify($then->type, $else->type),
            Prec::TERNARY,
        );
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

                // (int)3.9 is 3; a double out of the type's range has no defined result in C#.
                $truncated = Emitted::value('(int) ' . $this->wrap($value, Prec::UNARY), $target, Prec::UNARY);

                return in_array($plain->integralName(), self::NARROW_INTEGRALS, true) && $plain->integralName() !== 'uint'
                    ? $this->narrowed($truncated, $plain) : $truncated;
            case 'float':
                return $source->isFloat() ? $value : Emitted::value('(float) ' . $this->wrap($value, Prec::UNARY), Type::float(), Prec::UNARY);
            case 'bool':
                return $source->isBool() ? $value : Emitted::value('(bool) ' . $this->wrap($value, Prec::UNARY), Type::bool(), Prec::UNARY);
            case 'char':
                return $source->isChar() ? $value : $this->intToChar($this->charToInt($value));
            case 'string':
                return $this->asString($value);
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
            return Emitted::value('new ' . $this->classRef($symbol->fqn) . '()', $type);
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
        }

        return Emitted::value(
            'new ' . $this->runtime('AnonymousObject') . '([' . implode(', ', $items) . '])',
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
                $decl = $param->type !== null ? $this->typeDecl($type) . ' ' : '';
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
