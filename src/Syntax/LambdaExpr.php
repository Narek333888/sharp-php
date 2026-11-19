<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * x => x * 2, (a, b) => { ... }, static x => ...
 */
final class LambdaExpr extends Expr
{
    public function __construct(
        /** @var list<Parameter> */
        public readonly array $params,
        public readonly Block|Expr $body,
        public readonly bool $isStatic,
    ) {
    }
}
