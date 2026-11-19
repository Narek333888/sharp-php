<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.FormatException: text is not in the format a Parse method expects.
 */
class FormatException extends SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'One of the identified items was in an invalid format.';
}
