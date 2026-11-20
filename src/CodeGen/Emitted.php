<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\Semantics\MemberSymbol;
use SharpPhp\Semantics\Type;
use SharpPhp\Semantics\TypeSymbol;

/**
 * Result of emitting an expression: PHP code, its static type and precedence.
 *
 * kind:
 *  - value      a regular PHP expression
 *  - type       a class reference ($code is the class name as it should be written)
 *  - namespace  a namespace prefix of a qualified name ($namespace is dotted)
 *  - base       the "base" keyword
 *  - method     a method group; $code is the receiver prefix ("$this->", "self::", "Foo::")
 *  - function   a global PHP function ($code is the function name)
 */
final class Emitted
{
    /**
     * A storage location whose code can be evaluated again: a variable or a static property, then any
     * property and element accesses, with indexes made of names, numbers and arithmetic. No ++ or --
     * anywhere, as those change a variable each time the code runs.
     */
    private const string REPEATABLE_PATH = '/^(?!.*(?:\+\+|--))
        (?: \$[A-Za-z_\x80-\xff][\w\x80-\xff]*                # $local, $this
          | [\w\\\\]+::\$[A-Za-z_\x80-\xff][\w\x80-\xff]* )   # self::$field, \App\Config::$field
        (?: ->[A-Za-z_\x80-\xff][\w\x80-\xff]*                # ->property
          | \[[\w\x80-\xff$ +*\/%-]+\] )*                     # [$i], [0], [$i + 1]
    $/x';

    public function __construct(
        public readonly string $code,
        public readonly Type $type,
        public readonly int $prec = Prec::ATOM,
        public readonly string $kind = 'value',
        public readonly ?TypeSymbol $symbol = null,
        public readonly ?MemberSymbol $member = null,
        public readonly ?string $namespace = null,
        /** For values: name of the local variable this expression is, if any. */
        public readonly ?string $local = null,
        /** For int literals their value; for char literals the character's code ('A' => 65). */
        public readonly ?int $intValue = null,
        /** For static properties with accessors: the class prefix ("self::", "Config::") of get_X()/set_X(). */
        public readonly ?string $receiver = null,
    ) {
    }

    public static function value(string $code, Type $type, int $prec = Prec::ATOM, ?string $local = null): self
    {
        return new self($code, $type, $prec, 'value', null, null, null, $local);
    }

    public function isValue(): bool
    {
        return $this->kind === 'value';
    }

    /** True when evaluating the code twice is harmless (a variable or $this->prop). */
    public function isSimple(): bool
    {
        return $this->kind === 'value'
            && preg_match('/^\$[A-Za-z_\x80-\xff][\w\x80-\xff]*(->[A-Za-z_\x80-\xff][\w\x80-\xff]*)?$/', $this->code) === 1;
    }

    /**
     * True when the code is a storage location that can be evaluated again without side effects
     * ($a, self::$a, $this->a->b, $grid[$i + 1]), so that it can be repeated where it must be the same
     * place: $a[Op::index($a, $i)] = ... checks the bounds of the very array that is written to.
     *
     * Wider than isSimple(), which decides where repeating the code is cheaper than a temporary
     * variable; a write cannot go through a temporary, as it would change a copy of the array.
     */
    public function isRepeatablePath(): bool
    {
        return $this->kind === 'value' && preg_match(self::REPEATABLE_PATH, $this->code) === 1;
    }
}
