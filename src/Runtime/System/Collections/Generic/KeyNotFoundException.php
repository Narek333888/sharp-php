<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Collections\Generic;

/**
 * System.Collections.Generic.KeyNotFoundException: dictionary[key] for a missing key.
 */
class KeyNotFoundException extends \SharpPhp\Runtime\System\SystemException
{
    protected const ?string DEFAULT_MESSAGE = 'The given key was not present in the dictionary.';
}
