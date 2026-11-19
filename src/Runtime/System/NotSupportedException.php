<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class NotSupportedException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'Specified method is not supported.';
}
