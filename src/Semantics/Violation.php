<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

use SharpPhp\Syntax\Node;

/**
 * A rule broken by the program, found by one of the semantic rule classes
 * (AccessRules, ArgumentMatcher, ...). The caller turns it into a compile error,
 * at $at when the rule knows a more precise location than the caller.
 */
final class Violation
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?Node $at = null,
    ) {
    }
}
