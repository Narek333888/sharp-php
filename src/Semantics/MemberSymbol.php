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
     * Static property with accessor bodies. PHP has no static property hooks, so it is
     * compiled to get_Name()/set_Name($value) methods (the names C# uses internally).
     */
    public bool $isStaticAccessor = false;

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
}
