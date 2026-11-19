<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class FormatException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'One of the identified items was in an invalid format.';
}
