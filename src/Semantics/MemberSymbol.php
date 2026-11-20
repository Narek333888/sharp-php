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
        $name = $this->owner->csharpName() . '.' . $this->name;

        return $this->kind === 'method' ? $name . '(' . ParameterSymbol::describeTypes($this->params) . ')' : $name;
    }
}
