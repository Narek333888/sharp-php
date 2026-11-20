<?php

declare(strict_types=1);

namespace SharpPhp\CodeGen;

use SharpPhp\Syntax\Stmt;

/**
 * A statement of a lowered construct that the emitter writes with a callback: "using" becomes
 * try/finally statements whose try block continues with the next resource or the body.
 */
final class EmitStep extends Stmt
{
    public function __construct(public readonly \Closure $emit)
    {
    }
}
