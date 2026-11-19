<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class NullReferenceException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'Object reference not set to an instance of an object.';
}
