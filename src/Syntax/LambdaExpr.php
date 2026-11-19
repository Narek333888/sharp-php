<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class LambdaExpr extends Expr
{
    public function __construct(
        /** @var list<Parameter> */
        public readonly array $params,
        public readonly Node $body,
        public readonly bool $isStatic,
    ) {
    }
}
