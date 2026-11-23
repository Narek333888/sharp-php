<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * A member of a type: field, property, method, const, enum case or constructor.
 */
final class MemberSymbol
{
    /** Declared "abstract" (members of project classes only; interface members are not flagged). */
    public bool $isAbstract = false;

    /** Project properties: visibility of the set accessor, 'init', or null when there is none. */
    public ?string $setter = null;

    /** Project properties without accessor bodies: a get-only one can be assigned in a constructor. */
    public bool $isAutoProperty = false;

    /** Project fields declared readonly. */
    public bool $isReadonly = false;

    /**
     * An extension method: a static method of a non-generic static class whose first parameter is
     * declared "this", so that text.Shout() calls StringExtensions.Shout(text).
     */
    public bool $isExtension = false;

    /** The getter of a property of an extension block (C# 14), lowered to get_Name(): the property's name. */
    public ?string $extensionProperty = null;

    /** A static member of an extension block: the type it extends (string.Repeat(...) for extension(string)). */
    public ?Type $staticExtensionOf = null;

    /** @var array<string, string> runtime methods: type parameter => PHP parameter that receives its class (@sharp-reified) */
    public array $reified = [];

    /** A local function, compiled to a private method of the type around it. */
    public bool $isLocalFunction = false;

    /**
     * Runtime library: the instance method that "obj.Name(...)" calls when the static method Name
     * has a twin for instances (Regex.IsMatch(input, pattern) and regex.IsMatch(input)), see
     * "@sharp-instance Name". Its own name is the PHP one; $csharpName is "Name".
     */
    public ?MemberSymbol $instanceVariant = null;

    /**
     * A field or method that PHP code reads (in a class derived from a PHP class: Laravel's $casts,
     * rules()): the List/Dictionary/HashSet type it was declared with. A field holds a PHP array ($type
     * is that); a method returns one to PHP and keeps $type, as calls from SharpPHP convert it back.
     * Null for other members.
     */
    public ?Type $phpArrayOf = null;

    public ?string $csharpName = null;

    /**
     * A static method of the runtime library that implements a C# operator, from its "@sharp-operator"
     * tag: "+" or "-" (date + span, end - start; a one-parameter "-" is the negation -span), or
     * "compare" for <, >, <=, >=, == and != (the method returns negative, zero or positive).
     */
    public ?string $operator = null;

    /** Project constants: the initializer expression (const bool Verbose = true;). */
    public ?\SharpPhp\Syntax\Expr $constantValue = null;

    /**
     * Static property with accessor bodies. PHP has no static property hooks, so it is
     * compiled to get_Name()/set_Name($value) methods (the names C# uses internally).
     */
    public bool $isStaticAccessor = false;

    /** @var array{0: string, 1: int, 2: int}|null where it is declared: file, line, column */
    public ?array $location = null;

    /**
     * @param list<ParameterSymbol> $params
     * @param list<string> $typeParams
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly bool $isStatic,
        public Type $type,
        public readonly TypeSymbol $owner,
        public array $params = [],
        public array $typeParams = [],
        public readonly string $visibility = 'public',
    ) {
    }

    public function isValue(): bool
    {
        return $this->kind === 'field' || $this->kind === 'property';
    }

    /** "Shop.Item.Price" or, for a method, "Shop.Item.Buy(int, string)", as C# names members in diagnostics. */
    public function displayName(): string
    {
        $name = $this->owner->csharpName() . '.' . ($this->csharpName ?? $this->name);

        return $this->kind === 'method' ? $name . '(' . ParameterSymbol::describeTypes($this->params) . ')' : $name;
    }
}
