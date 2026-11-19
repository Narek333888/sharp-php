<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class InvalidCastException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'Specified cast is not valid.';
}
