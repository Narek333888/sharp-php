<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

/**
 * C#'s extension methods: static methods of non-generic static classes whose first parameter is
 * declared "this".
 *
 *   public static class StringExtensions
 *   {
 *       public static string Shout(this string text) => text.ToUpper() + "!";
 *   }
 *
 * "hi".Shout() then calls StringExtensions.Shout("hi"), when the receiver's type has no method of
 * that name (instance methods come first), the class is in a namespace the file sees (its own, one
 * around it, or an imported one), and the receiver converts to the "this" parameter by an identity,
 * reference or boxing conversion (see Conversions::isReceiverConversion()).
 *
 * Explicit type arguments ("x".Print<bool>()) choose the methods with that many type parameters, and
 * the receiver must convert to the "this" parameter with them: Print<T>(this T arg) then takes a bool.
 */
final class ExtensionMethods
{
    public function __construct(
        private readonly SymbolTable $symbols,
        private readonly Conversions $conversions,
    ) {
    }

    /**
     * The extension methods a receiver of type $receiver can call where $context applies: all of them,
     * or the ones named $name (case-insensitively, like PHP method names).
     *
     * @param list<Type>|null $typeArgs the call's explicit type arguments; null when it has none
     * @return list<MemberSymbol>
     */
    public function applicable(Type $receiver, ResolutionContext $context, ?string $name = null, ?array $typeArgs = null): array
    {
        return array_values(array_filter(
            $this->named($context, $name, $typeArgs),
            fn (MemberSymbol $method): bool => $this->accepts($method, $receiver, $typeArgs),
        ));
    }

    /**
     * What receiver.$name(...) can call: the applicable extension methods whose "this" type is the most
     * specific (one for "this string" before one for "this object"). Empty when none applies; more than
     * one when the call is ambiguous.
     *
     * @param list<Type>|null $typeArgs the call's explicit type arguments; null when it has none
     * @return list<MemberSymbol>
     */
    public function best(Type $receiver, ResolutionContext $context, string $name, ?array $typeArgs = null): array
    {
        // Property getters of extension blocks (get_Name) are found through bestProperty().
        $candidates = array_values(array_filter($this->applicable($receiver, $context, $name, $typeArgs), static fn (MemberSymbol $m): bool => $m->extensionProperty === null));

        return $this->mostSpecific($candidates, $typeArgs);
    }

    /**
     * The extension method C# names when receiver.$name(...) finds none that applies (CS1929, "requires a
     * receiver of type ..."): the first one in scope of that name that the receiver cannot be passed to.
     * A generic one counts only when the call gives its type arguments, or when its "this" parameter does
     * not use them: C# drops a method whose type arguments it cannot infer from the receiver (CS1061).
     *
     * @param list<Type>|null $typeArgs the call's explicit type arguments; null when it has none
     */
    public function rejecting(Type $receiver, ResolutionContext $context, string $name, ?array $typeArgs = null): ?MemberSymbol
    {
        foreach ($this->named($context, $name, $typeArgs) as $method) {
            if ($method->extensionProperty === null && !$this->accepts($method, $receiver, $typeArgs)
                && ($typeArgs !== null || self::thisType($method)->equals($method->params[0]->type))) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The type of the method's "this" parameter for a call with these explicit type arguments ("bool"
     * for Print<T>(this T arg) called as Print<bool>()); its other type parameters stand for any type.
     *
     * @param list<Type>|null $typeArgs
     */
    public static function thisType(MemberSymbol $method, ?array $typeArgs = null): Type
    {
        $map = array_fill_keys($method->typeParams, Type::mixed());
        foreach ($method->typeParams as $i => $param) {
            if (isset($typeArgs[$i])) {
                $map[$param] = $typeArgs[$i];
            }
        }

        return $method->params[0]->type->substitute($map);
    }

    /**
     * What receiver.$name can read: the getters of extension properties (C# 14 extension blocks) named
     * $name that apply to the receiver, the most specific first; more than one when ambiguous.
     *
     * @return list<MemberSymbol>
     */
    public function bestProperty(Type $receiver, ResolutionContext $context, string $name): array
    {
        $candidates = array_values(array_filter($this->applicable($receiver, $context, 'get_' . $name), static fn (MemberSymbol $m): bool => $m->extensionProperty === $name));

        return $this->mostSpecific($candidates, null);
    }

    /**
     * Static members of extension blocks that Type.$name means: methods (property false) or property
     * getters (property true) of blocks extending $type, in a namespace the file sees.
     *
     * @return list<MemberSymbol>
     */
    public function staticMembers(Type $type, ResolutionContext $context, ?string $name, bool $property): array
    {
        $namespaces = array_flip($context->namespacesInScope());
        $found = [];
        foreach ($this->symbols->staticExtensions() as $member) {
            $extended = $member->staticExtensionOf;
            if ($extended === null || ($member->extensionProperty !== null) !== $property || !isset($namespaces[$member->owner->csharpNamespace()])) {
                continue;
            }
            $memberName = $member->extensionProperty ?? $member->name;
            if (($name === null || ($property ? $memberName === $name : strcasecmp($memberName, $name) === 0)) && $this->sameTypeAs($type, $extended)) {
                $found[] = $member;
            }
        }

        return $found;
    }

    /**
     * A type used as a receiver (string. is the runtime class String_, List<int>. a generic class) is the
     * type an extension block names.
     */
    private function sameTypeAs(Type $receiver, Type $extended): bool
    {
        $class = static function (Type $t): ?string {
            if ($t->kind === 'class') {
                return strtolower(ltrim((string) $t->class, '\\'));
            }
            $keyword = match ($t->kind) {
                'string' => 'string', 'int' => 'int', 'float' => 'double', 'bool' => 'bool', 'char' => 'char', 'mixed' => 'object',
                default => null,
            };
            $dotted = $keyword !== null ? TypeResolver::KEYWORD_RUNTIME_CLASSES[$keyword] : null;

            return $dotted !== null ? strtolower(Names::phpClass($dotted)) : null;
        };
        $a = $class($receiver);
        $b = $class($extended);

        return $a !== null && $a === $b;
    }

    /**
     * The extension methods named $name (all when null) in a namespace the file sees; with explicit type
     * arguments, the ones with that many type parameters.
     *
     * @param list<Type>|null $typeArgs
     * @return list<MemberSymbol>
     */
    private function named(ResolutionContext $context, ?string $name, ?array $typeArgs): array
    {
        $namespaces = array_flip($context->namespacesInScope());
        $found = [];
        foreach ($this->symbols->extensions() as $method) {
            if (($name === null || strcasecmp($method->name, $name) === 0)
                && isset($namespaces[$method->owner->csharpNamespace()])
                && ($typeArgs === null || count($method->typeParams) === count($typeArgs))) {
                $found[] = $method;
            }
        }

        return $found;
    }

    /**
     * @param list<MemberSymbol> $candidates
     * @param list<Type>|null $typeArgs
     * @return list<MemberSymbol>
     */
    private function mostSpecific(array $candidates, ?array $typeArgs): array
    {
        return array_values(array_filter($candidates, function (MemberSymbol $candidate) use ($candidates, $typeArgs): bool {
            foreach ($candidates as $other) {
                if ($other !== $candidate && $this->moreSpecific($other, $candidate, $typeArgs)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Whether a receiver of type $receiver can be passed as the method's "this" parameter.
     *
     * @param list<Type>|null $typeArgs
     */
    private function accepts(MemberSymbol $method, Type $receiver, ?array $typeArgs): bool
    {
        $thisType = self::thisType($method, $typeArgs);
        // As far as the compiler knows, a value of unknown type (object, untyped PHP code) converts to
        // anything, so it would match every extension method. C# binds it to "this object" ones only.
        if ($receiver->isUnknown()) {
            return $thisType->isUnknown();
        }

        return $this->conversions->isReceiverConversion($receiver, $thisType);
    }

    /**
     * $a takes fewer receivers than $b: its "this" type converts to $b's, not the other way round.
     *
     * @param list<Type>|null $typeArgs
     */
    private function moreSpecific(MemberSymbol $a, MemberSymbol $b, ?array $typeArgs): bool
    {
        $thisA = self::thisType($a, $typeArgs);
        $thisB = self::thisType($b, $typeArgs);
        // Any type converts to and from an unknown one, so that comparison says nothing: object is the
        // least specific "this" type.
        if ($thisA->isUnknown() || $thisB->isUnknown()) {
            return !$thisA->isUnknown();
        }

        return $this->conversions->isImplicit($thisA, $thisB) && !$this->conversions->isImplicit($thisB, $thisA);
    }
}
