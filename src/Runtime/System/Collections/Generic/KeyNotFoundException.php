<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

class KeyNotFoundException extends \SharpPhp\Runtime\System\SystemException
{
    protected const DEFAULT_MESSAGE = 'The given key was not present in the dictionary.';
}
