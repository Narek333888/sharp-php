<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

/**
 * PHP operator precedence (higher binds tighter). Used to emit the minimum number of parentheses.
 */
final class Prec
{
    public const ATOM = 100;
    public const POW = 95;
    public const UNARY = 90;
    public const INSTANCEOF = 85;
    public const NOT = 80;
    public const MUL = 70;
    public const ADD = 60;
    public const SHIFT = 55;
    public const CONCAT = 50;
    public const REL = 45;
    public const EQ = 40;
    public const BAND = 35;
    public const BXOR = 30;
    public const BOR = 25;
    public const AND = 20;
    public const OR = 15;
    public const COALESCE = 12;
    public const TERNARY = 10;
    public const ASSIGN = 5;
    public const LOWEST = 1;

    /** Non-associative levels: an operand at the same level always needs parentheses. */
    public const NON_ASSOCIATIVE = [self::REL, self::EQ, self::TERNARY, self::INSTANCEOF];
}
