<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class TimeoutException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'The operation has timed out.';
}
