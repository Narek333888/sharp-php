<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * A class, interface, enum or record known to the compiler: either declared in the
 * project or loaded through reflection from PHP (runtime library, vendor packages).
 */
final class TypeSymbol
{
    /** @var array<string, MemberSymbol> */
    public array $members = [];

    public ?string $baseClass = null;

    /** @var list<string> */
    public array $interfaces = [];

    /** @var list<string> */
    public array $typeParams = [];

    /** @var array<string, Type> */
    public array $typeParamTypes = [];

    public bool $isAbstract = false;

    public bool $isRecord = false;

    public bool $isStruct = false;

    public bool $hasConstructor = false;
    /** Declares a finalizer (~Name()), compiled to __destruct. */
    public bool $hasFinalizer = false;

    /** @var list<ParameterSymbol>|null parameters of the declared constructor (null: none declared, or unknown) */
    public ?array $constructorParams = null;

    /** Visibility of the declared constructor (project classes). */
    public ?string $constructorVisibility = null;

    /** Element type when iterated (runtime collections declare it with @sharp-element). */
    public ?Type $elementType = null;

    /** @var array{0: string, 1: int, 2: int}|null where it is declared: file, line, column */
    public ?array $location = null;

    /**
     * A delegate type (kind "delegate"): its declaration, whose Invoke method is the signature, and
     * where it was declared. TypeResolver makes it the closure type of that signature.
     */
    public ?\SharpPhp\Syntax\TypeDecl $delegateDecl = null;

    public ?ResolutionContext $delegateContext = null;

    /** @var array<string, 'in'|'out'> variant type parameters (interface IBox<out T>), by name */
    public array $variance = [];

    /**
     * Generic arguments of the base class and interfaces, in terms of this type's own parameters:
     * for "class Box<T> : IBox<T>", ['ibox' => [T]]. Keyed by lowercase FQN.
     *
     * @var array<string, list<Type>>
     */
    public array $supertypeArgs = [];

    public function __construct(
        public readonly string $kind,
        public readonly string $fqn,
        public readonly bool $external,
    ) {
    }

    public function isInterface(): bool
    {
        return $this->kind === 'interface';
    }

    public function isEnum(): bool
    {
        return $this->kind === 'enum';
    }

    public function csharpName(): string
    {
        return Names::csharpName($this->fqn);
    }

    /** "Person" for App.Models.Person. */
    public function shortName(): string
    {
        return substr((string) strrchr('.' . $this->csharpName(), '.'), 1);
    }

    /** "App.Models" for App.Models.Person; "" in the global namespace. */
    public function csharpNamespace(): string
    {
        $name = $this->csharpName();
        $dot = strrpos($name, '.');

        return $dot === false ? '' : substr($name, 0, $dot);
    }

    public function addMember(MemberSymbol $member): void
    {
        $this->members[$member->name] = $member;
    }

    /**
     * Generic arguments of a receiver type mapped onto this symbol's type parameters.
     *
     * @return array<string, Type>
     */
    public function substitutionFor(Type $receiver): array
    {
        if ($this->typeParams === [] || $receiver->kind !== 'class') {
            return [];
        }
        $map = [];
        foreach ($this->typeParams as $i => $name) {
            $map[$name] = $receiver->args[$i] ?? Type::mixed();
        }

        return $map;
    }
}
