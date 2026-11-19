<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class InvalidOperationException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'Operation is not valid due to the current state of the object.';
}
