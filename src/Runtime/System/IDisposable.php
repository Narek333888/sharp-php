<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.IDisposable: types that release resources in Dispose().
 */
interface IDisposable
{
    /**
     * obj.Dispose(): releases what the object holds (a file, a connection, ...). A using statement
     * or declaration calls it when its scope is left, also by an exception.
     */
    public function Dispose(): void;
}
