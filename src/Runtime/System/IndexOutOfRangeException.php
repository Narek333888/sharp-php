<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class IndexOutOfRangeException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'Index was outside the bounds of the array.';
}
