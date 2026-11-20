<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.StringComparison: how string.Equals, Compare, StartsWith, EndsWith, Contains, IndexOf,
 * LastIndexOf and Replace compare text:
 *
 *   name.Equals("ann", StringComparison.OrdinalIgnoreCase)
 *
 * SharpPHP has no cultures, so the culture kinds compare as the ordinal ones do; the IgnoreCase kinds
 * ignore the case of every Unicode letter.
 */
enum StringComparison: int
{
    case CurrentCulture = 0;
    case CurrentCultureIgnoreCase = 1;
    case InvariantCulture = 2;
    case InvariantCultureIgnoreCase = 3;
    case Ordinal = 4;
    case OrdinalIgnoreCase = 5;

    /** @internal */
    public function ignoresCase(): bool
    {
        return $this === self::CurrentCultureIgnoreCase || $this === self::InvariantCultureIgnoreCase || $this === self::OrdinalIgnoreCase;
    }
}
