<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

class NotImplementedException extends SystemException
{
    protected const DEFAULT_MESSAGE = 'The method or operation is not implemented.';
}
