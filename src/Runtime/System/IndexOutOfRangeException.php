<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.IndexOutOfRangeException: an array index outside its bounds.
 */
class IndexOutOfRangeException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'Index was outside the bounds of the array.';
}
