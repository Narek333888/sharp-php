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
        // Property getters of extension blocks (get_Name) are found through bestProperty().
        $candidates = array_values(array_filter($this->applicable($receiver, $context, $name), static fn (MemberSymbol $m): bool => $m->extensionProperty === null));

        return $this->mostSpecific($candidates);
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

        return $this->mostSpecific($candidates);
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
     * @param list<MemberSymbol> $candidates
     * @return list<MemberSymbol>
     */
    private function mostSpecific(array $candidates): array
    {
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
