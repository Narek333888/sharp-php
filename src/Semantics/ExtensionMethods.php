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
 * around it, or an imported one), and the receiver converts to the "this" parameter.
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
     * @return list<MemberSymbol>
     */
    public function applicable(Type $receiver, ResolutionContext $context, ?string $name = null): array
    {
        $namespaces = array_flip($context->namespacesInScope());
        $found = [];
        foreach ($this->symbols->extensions() as $method) {
            if (($name === null || strcasecmp($method->name, $name) === 0)
                && isset($namespaces[$method->owner->csharpNamespace()])
                && $this->accepts($method, $receiver)) {
                $found[] = $method;
            }
        }

        return $found;
    }

    /**
     * What receiver.$name(...) can call: the applicable extension methods whose "this" type is the most
     * specific (one for "this string" before one for "this object"). Empty when none applies; more than
     * one when the call is ambiguous.
     *
     * @return list<MemberSymbol>
     */
    public function best(Type $receiver, ResolutionContext $context, string $name): array
    {
        $candidates = $this->applicable($receiver, $context, $name);

        return array_values(array_filter($candidates, function (MemberSymbol $candidate) use ($candidates): bool {
            foreach ($candidates as $other) {
                if ($other !== $candidate && $this->moreSpecific($other, $candidate)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Whether a receiver of type $receiver can be passed as the method's "this" parameter. */
    private function accepts(MemberSymbol $method, Type $receiver): bool
    {
        $thisType = self::thisType($method);
        // As far as the compiler knows, a value of unknown type (object, untyped PHP code) converts to
        // anything, so it would match every extension method. C# binds it to "this object" ones only.
        if ($receiver->isUnknown()) {
            return $thisType->isUnknown();
        }

        return $this->conversions->isImplicit($receiver, $thisType);
    }

    /** $a takes fewer receivers than $b: its "this" type converts to $b's, not the other way round. */
    private function moreSpecific(MemberSymbol $a, MemberSymbol $b): bool
    {
        $thisA = self::thisType($a);
        $thisB = self::thisType($b);
        // Any type converts to and from an unknown one, so that comparison says nothing: object is the
        // least specific "this" type.
        if ($thisA->isUnknown() || $thisB->isUnknown()) {
            return !$thisA->isUnknown();
        }

        return $this->conversions->isImplicit($thisA, $thisB) && !$this->conversions->isImplicit($thisB, $thisA);
    }

    /** The type of the "this" parameter, the method's own type parameters standing for any type. */
    private static function thisType(MemberSymbol $method): Type
    {
        return $method->params[0]->type->substitute(array_fill_keys($method->typeParams, Type::mixed()));
    }
}
