<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen\Concerns;

use SharpPhp\CodeGen\CodeWriter;
use SharpPhp\CodeGen\Emitted;
use SharpPhp\CodeGen\FunctionContext;
use SharpPhp\CodeGen\LambdaContext;
use SharpPhp\CodeGen\Prec;
use SharpPhp\Semantics\Names;
use SharpPhp\Semantics\Type;
use SharpPhp\Syntax\AnonymousObjectExpr;
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
                : new Emitted((string) $e->intValue(), Type::int(), intValue: $e->intValue()),
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
        if ($hole->format !== null) {
            return Emitted::value($this->runtime('Op') . '::format(' . $value->code . ', ' . self::phpString($hole->format) . ')', Type::string());
        }

        return $this->asString($value);
    }

    /** Converts a value to a string the way C# would (true => "True", objects => ToString()). */
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
        if ($value->intValue !== null) {
            return Emitted::value((string) $value->intValue, Type::int());
        }

        return Emitted::value('\\mb_ord(' . $value->code . ')', Type::int());
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

            return Emitted::value($e->postfix ? $target->code . $op : $op . $target->code, $target->type, Prec::UNARY);
        }

        $operand = $op === '!' ? $this->convert($e->operand, Type::bool()) : $this->charToInt($this->expr($e->operand));

        return match ($op) {
            '-' => Emitted::value('-' . $this->unaryOperand($operand, '-'), $operand->type->isNumeric() ? $operand->type : Type::mixed(), Prec::UNARY),
            '+' => $operand,
            '!' => Emitted::value('!' . $this->wrap($operand, Prec::NOT), Type::bool(), Prec::NOT),
            '~' => Emitted::value('~' . $this->unaryOperand($operand, '~'), Type::int(), Prec::UNARY),
            default => throw $this->error('SP1010', "Unsupported operator '$op'", $e),
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

        $lt = $l->type;
        $rt = $r->type;
        $numericType = $lt->isFloat() || $rt->isFloat() ? Type::float() : Type::int();
        $bothNumeric = $lt->isNumeric() && $rt->isNumeric();
        $bothInt = $lt->isInt() && $rt->isInt();
        $op_ = $this->runtime('Op');

        switch ($op) {
            case '+':
                if ($lt->isString() || $rt->isString()) {
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
                    return Emitted::value("\\intdiv({$l->code}, {$r->code})", Type::int());
                }
                if ($bothNumeric) {
                    return $this->infix('/', $l, $r, Prec::MUL, Type::float());
                }

                return Emitted::value("$op_::div({$l->code}, {$r->code})", Type::mixed());
            case '%':
                if ($bothInt) {
                    return $this->infix('%', $l, $r, Prec::MUL, Type::int());
                }
                if ($bothNumeric) {
                    return Emitted::value("\\fmod({$l->code}, {$r->code})", Type::float());
                }

                return Emitted::value("$op_::mod({$l->code}, {$r->code})", Type::mixed());
            case '<<':
            case '>>':
                return $this->infix($op, $l, $r, Prec::SHIFT, Type::int());
            case '&':
            case '|':
            case '^':
                $prec = ['&' => Prec::BAND, '|' => Prec::BOR, '^' => Prec::BXOR][$op];
                if ($lt->isBool() && $rt->isBool()) {
                    return Emitted::value('(bool) (' . $this->infix($op, $l, $r, $prec, Type::int())->code . ')', Type::bool(), Prec::UNARY);
                }

                return $this->infix($op, $l, $r, $prec, Type::int());
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
        $target = $this->place($e->target);
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
        $value = $this->expr($e->value);
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
        $bothNumeric = $type->isNumeric() && $vt->isNumeric();
        $bothInt = $type->isInt() && $vt->isInt();

        // Use PHP's compound operator when it has the same meaning as the C# one.
        $compound = match ($binaryOp) {
            '+' => $type->isString() || $vt->isString() ? '.=' : ($bothNumeric ? '+=' : null),
            '/' => $bothNumeric && !$bothInt ? '/=' : null,
            '%' => $bothInt ? '%=' : null,
            '-', '*', '<<', '>>', '&', '|', '^' => $binaryOp . '=',
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
                if ($source->isInt()) {
                    return $value;
                }
                if ($sourceSymbol !== null && $sourceSymbol->isEnum()) {
                    return Emitted::value($this->wrap($value, Prec::ATOM) . '->value', Type::int());
                }

                return Emitted::value('(int) ' . $this->wrap($value, Prec::UNARY), Type::int(), Prec::UNARY);
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

    private function defaultValue(?Type $type): Emitted
    {
        return match ($type?->kind) {
            'int' => Emitted::value('0', Type::int()),
            'float' => Emitted::value('0.0', Type::float()),
            'bool' => Emitted::value('false', Type::bool()),
            'char' => Emitted::value('"\\0"', Type::char()),
            default => Emitted::value('null', $type?->withNullable() ?? Type::null()),
        };
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
        $type = $e->type !== null ? $this->resolveType($e->type) : $expected?->withNullable(false);
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
        if ($symbol->isStruct && $e->args === [] && $ctorParams !== null && $ctorParams !== [] && !$ctorParams[0]->optional) {
            throw $this->error('SP1010', "Structs are classes in SharpPHP, so 'new {$symbol->csharpName()}()' has no implicit parameterless constructor; pass the constructor's arguments", $e);
        }
        if ($ctorParams !== null) {
            $name = $symbol->csharpName();
            $this->checkArguments($e->args, $ctorParams, $name, $this->signature("$name.{$symbol->shortName()}", $ctorParams), $e, constructor: true, runtime: self::isRuntimeLibrary($symbol));
        }
        [$args] = $this->arguments($e->args, $ctorParams ?? [], $symbol->substitutionFor($type), runtime: $symbol->external);
        $of = $this->collectionElementSpec($type, $symbol);
        $items = $e->initializer->items ?? [];
        $elementType = $this->elementTypeOf($type);

        // new List<int> { 1, 2, 3 }  =>  new List_([1, 2, 3], of: 'int')
        if ($items !== [] && $args === '' && $of !== null && $symbol->fqn !== Names::RUNTIME_NAMESPACE . '\\System\\Collections\\Generic\\Dictionary'
            && array_reduce($items, static fn (bool $c, InitializerItem $i): bool => $c && $i->kind === 'element', true)) {
            $elements = array_map(fn (InitializerItem $i): string => $this->convert($i->values[0], $elementType)->code, $items);

            return Emitted::value("new $class([" . implode(', ', $elements) . "], of: $of)", $type);
        }

        $argList = implode(', ', array_filter([$args, $of !== null ? "of: $of" : '']));
        $code = "new $class($argList)";
        if ($items === []) {
            return Emitted::value($code, $type);
        }

        $kinds = array_unique(array_map(static fn (InitializerItem $i): string => $i->kind, $items));
        if (count($kinds) > 1) {
            throw $this->error('SP0747', 'Invalid initializer member declarator: object and collection initializers cannot be mixed', $e->initializer ?? $e);
        }
        $op = $this->runtime('Op');
        $kind = $kinds[0];

        if ($kind === 'member') {
            $pairs = [];
            foreach ($items as $item) {
                $member = $this->symbols->findMember($symbol, (string) $item->name);
                if ($member === null && !$symbol->external) {
                    throw $this->error('SP0117', "'{$symbol->csharpName()}' does not contain a definition for '{$item->name}'", $item);
                }
                if ($member !== null) {
                    $this->requireAccessible($member, $item);
                    $this->requireWritable($member, $item, initializer: true);
                }
                $memberType = $member !== null ? $member->type->substitute($member->owner->substitutionFor($type)) : Type::mixed();
                $pairs[] = self::phpString((string) $item->name) . ' => ' . $this->convert($item->values[0], $memberType)->code;
            }

            return Emitted::value("$op::init($code, [" . implode(', ', $pairs) . '])', $type);
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

        if ($e->elements === null) {
            $size = $this->expr($e->size ?? throw new \LogicException('An array without elements has a size'), Type::int());
            $default = $this->defaultValue($elementType);

            return Emitted::value("\\array_fill(0, {$size->code}, {$default->code})", Type::array($elementType ?? Type::mixed()));
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

            if ($e->body instanceof Block) {
                $outerWriter = $this->w;
                $this->w = new CodeWriter();
                $this->w->sourceLine = $outerWriter->sourceLine;
                $this->w->indent();
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
                $code = 'function (' . $paramCode . ')' . $this->useClause($context) . " {\n" . $body . "\n}";
                $returnType = $expectedReturn ?? Type::mixed();
            } else {
                $value = $this->inFunction($function, fn (): Emitted => $this->expr($e->body, $expectedReturn));
                $returnType = $expectedReturn !== null && !$expectedReturn->isUnknown() ? $expectedReturn : $value->type;
                $byReference = in_array(true, $context->captures, true);
                $code = $byReference
                    ? 'function (' . $paramCode . ')' . $this->useClause($context) . " {\n    return " . $value->code . ";\n}"
                    : 'fn (' . $paramCode . ') => ' . $value->code;
            }
            if ($e->isStatic) {
                $code = 'static ' . $code;
            }

            return Emitted::value($code, Type::closure([...$paramTypes, $returnType]), Prec::LOWEST);
        }, $context);
    }

    private function useClause(LambdaContext $context): string
    {
        if ($context->captures === []) {
            return '';
        }
        $vars = [];
        foreach ($context->captures as $name => $byRef) {
            $vars[] = ($byRef ? '&$' : '$') . $name;
        }

        return ' use (' . implode(', ', $vars) . ')';
    }
}
