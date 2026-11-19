<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

/**
 * default(T), or default with the type taken from the context.
 */
final class DefaultExpr extends Expr
{
    public function __construct(
        public readonly ?TypeRef $type,
    ) {
    }
}
