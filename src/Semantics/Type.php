<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * Static type of an expression as far as the compiler can tell.
 *
 * kinds: int float string char bool mixed void null array iterable closure class typeparam
 *  - char:      a C# char; at runtime it is a one-character string, but it converts to int like in C#
 *  - class:     $class is the PHP FQN, $args are generic arguments
 *  - array:     $args[0] is the element type
 *  - iterable:  $args[0] is the element type
 *  - closure:   $args are parameter types followed by the return type (Func<int, string>)
 *  - typeparam: $param is the name, $class is the constraint (if any)
 */
final class Type
{
    /** Constraints that numbers and strings satisfy too, so they cannot become PHP type declarations. */
    public const SCALAR_INTERFACES = [
        'SharpPhp\\Runtime\\System\\IComparable',
        'SharpPhp\\Runtime\\System\\IEquatable',
    ];

    /** Marks the array type of PHP code (see phpArray()). */
    public const PHP_ARRAY = 'php';

    /** Start of an anonymous type's name ("<anonymous type: int Id>"); see SymbolTable::anonymousType(). */
    public const ANONYMOUS_PREFIX = '<anonymous type';

    /** The runtime class of every anonymous object. */
    public const ANONYMOUS_CLASS = Names::RUNTIME_NAMESPACE . '\\AnonymousObject';

    /** @var array<string, self> shared instances of the simple types */
    private static array $cache = [];

    /**
     * @param list<Type> $args
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $class = null,
        public readonly array $args = [],
        public readonly bool $nullable = false,
        public readonly ?string $param = null,
    ) {
    }

    public static function int(): self
    {
        return self::$cache['int'] ??= new self('int');
    }

    public static function float(): self
    {
        return self::$cache['float'] ??= new self('float');
    }

    public static function string(): self
    {
        return self::$cache['string'] ??= new self('string');
    }

    public static function char(): self
    {
        return self::$cache['char'] ??= new self('char');
    }

    public static function bool(): self
    {
        return self::$cache['bool'] ??= new self('bool');
    }

    public static function mixed(): self
    {
        return self::$cache['mixed'] ??= new self('mixed');
    }

    public static function void(): self
    {
        return self::$cache['void'] ??= new self('void');
    }

    public static function null(): self
    {
        return self::$cache['null'] ??= new self('null');
    }

    public static function array(?Type $element = null): self
    {
        return new self('array', null, [$element ?? self::mixed()]);
    }

    /**
     * PHP's own array type, from the signatures of PHP code (functions, Composer packages): a list or a
     * key => value map. SharpPHP converts List, Dictionary and HashSet to and from it at the boundary.
     */
    public static function phpArray(?Type $element = null): self
    {
        return new self('array', null, [$element ?? self::mixed()], false, self::PHP_ARRAY);
    }

    public function isPhpArray(): bool
    {
        return $this->kind === 'array' && $this->param === self::PHP_ARRAY;
    }

    public static function iterable(?Type $element = null): self
    {
        return new self('iterable', null, [$element ?? self::mixed()]);
    }

    /** @param list<Type> $args */
    public static function closure(array $args = []): self
    {
        return new self('closure', null, $args);
    }

    /** @param list<Type> $args */
    public static function class(string $fqn, array $args = [], bool $nullable = false): self
    {
        return new self('class', ltrim($fqn, '\\'), $args, $nullable);
    }

    public static function typeParam(string $name, ?string $constraint = null): self
    {
        return new self('typeparam', $constraint, [], false, $name);
    }

    public function withNullable(bool $nullable = true): self
    {
        return $this->nullable === $nullable ? $this : new self($this->kind, $this->class, $this->args, $nullable, $this->param);
    }

    public function isInt(): bool
    {
        return $this->kind === 'int';
    }

    public function isFloat(): bool
    {
        return $this->kind === 'float';
    }

    public function isNumeric(): bool
    {
        return $this->kind === 'int' || $this->kind === 'float';
    }

    public function isString(): bool
    {
        return $this->kind === 'string';
    }

    public function isChar(): bool
    {
        return $this->kind === 'char';
    }

    public function isBool(): bool
    {
        return $this->kind === 'bool';
    }

    public function isClass(): bool
    {
        return $this->kind === 'class';
    }

    public function isNull(): bool
    {
        return $this->kind === 'null';
    }

    /** An anonymous type (new { Id = 1 }). */
    public function isAnonymous(): bool
    {
        return $this->kind === 'class' && self::isAnonymousName((string) $this->class);
    }

    public static function isAnonymousName(string $class): bool
    {
        return str_starts_with($class, self::ANONYMOUS_PREFIX);
    }

    /** True when nothing useful is known statically. */
    public function isUnknown(): bool
    {
        return $this->kind === 'mixed' || ($this->kind === 'typeparam' && $this->class === null);
    }

    /** Scalars that are copied by value and have a non-null default. */
    public function isValueType(): bool
    {
        return in_array($this->kind, ['int', 'float', 'bool', 'char'], true);
    }

    public function element(): self
    {
        return match ($this->kind) {
            'array', 'iterable' => $this->args[0] ?? self::mixed(),
            'string' => self::char(),
            default => self::mixed(),
        };
    }

    /** Return type of a closure type (last generic argument). */
    public function closureReturn(): self
    {
        return $this->kind === 'closure' && $this->args !== [] ? $this->args[array_key_last($this->args)] : self::mixed();
    }

    /**
     * @param array<string, Type> $map
     */
    public function substitute(array $map): self
    {
        if ($map === []) {
            return $this;
        }
        if ($this->kind === 'typeparam' && isset($map[$this->param])) {
            $replacement = $map[$this->param];

            return $this->nullable ? $replacement->withNullable() : $replacement;
        }
        if ($this->args === []) {
            return $this;
        }

        return new self($this->kind, $this->class, array_map(static fn (Type $t): Type => $t->substitute($map), $this->args), $this->nullable, $this->param);
    }

    /**
     * PHP type declaration for this type, or null when no declaration should be emitted.
     *
     * @param callable(string): string $classRef how to write a class name (imports)
     */
    public function phpDeclaration(callable $classRef): ?string
    {
        $base = match ($this->kind) {
            'int', 'float', 'string', 'bool', 'array', 'iterable', 'void' => $this->kind,
            'char' => 'string',
            'mixed', 'null' => 'mixed',
            'closure' => '\\Closure',
            'class' => $classRef((string) $this->class),
            'typeparam' => $this->class !== null && !in_array($this->class, self::SCALAR_INTERFACES, true) ? $classRef($this->class) : 'mixed',
            default => null,
        };
        if ($base === null) {
            return null;
        }
        if ($this->nullable && !in_array($base, ['mixed', 'void'], true)) {
            return '?' . $base;
        }

        return $base;
    }

    /** Type spec string understood by the runtime type checks (SharpPhp\Runtime\TypeCheck). */
    public function runtimeSpec(): string
    {
        $base = match ($this->kind) {
            'int', 'float', 'string', 'char', 'bool', 'array' => $this->kind,
            'class' => $this->isAnonymous() ? self::ANONYMOUS_CLASS : (string) $this->class,
            'closure' => 'Closure',
            default => 'mixed',
        };

        return $this->nullable && $base !== 'mixed' ? '?' . $base : $base;
    }

    public function equals(Type $other): bool
    {
        return $this->describe() === $other->describe();
    }

    /** C#-style description for diagnostics. */
    public function describe(): string
    {
        $text = match ($this->kind) {
            'int', 'float', 'string', 'char', 'bool', 'void' => $this->kind === 'float' ? 'double' : $this->kind,
            'mixed' => 'object',
            'null' => 'null',
            'array' => $this->isPhpArray() ? 'array' : $this->element()->describe() . '[]',
            'iterable' => 'IEnumerable<' . $this->element()->describe() . '>',
            'closure' => 'delegate',
            'typeparam' => (string) $this->param,
            'class' => Names::csharpName((string) $this->class) . ($this->args !== []
                ? '<' . implode(', ', array_map(static fn (Type $t): string => $t->describe(), $this->args)) . '>'
                : ''),
            default => $this->kind,
        };

        return $this->nullable ? $text . '?' : $text;
    }
}
