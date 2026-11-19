<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * A call: callee(arguments).
 */
final class InvocationExpr extends Expr
{
    public function __construct(
        public readonly Expr $callee,
        /** @var list<Argument> */
        public readonly array $args,
    ) {
    }
}
