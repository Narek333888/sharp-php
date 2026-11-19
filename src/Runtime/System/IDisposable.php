<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

interface IDisposable
{
    public function Dispose(): void;
}
