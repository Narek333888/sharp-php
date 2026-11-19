<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.IDisposable: types that release resources in Dispose().
 */
interface IDisposable
{
    public function Dispose(): void;
}
