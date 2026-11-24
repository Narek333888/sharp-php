<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\Syntax\Expr;

/**
 * An expression the emitter has evaluated already, put back into a syntax tree it builds: the receiver of a
 * ?. chain, evaluated once and known not to be null, under the rest of the chain (see conditionalAccess()).
 */
final class EmittedExpr extends Expr
{
    public function __construct(public readonly Emitted $value)
    {
    }
}
