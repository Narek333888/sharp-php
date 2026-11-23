<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.StringSplitOptions for string.Split: text.Split(' ', StringSplitOptions.RemoveEmptyEntries).
 * A flags enum, as in .NET: StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries.
 */
enum StringSplitOptions: int implements \SharpPhp\Runtime\FlagsEnum
{
    case None = 0;
    case RemoveEmptyEntries = 1;
    case TrimEntries = 2;
    /** @internal RemoveEmptyEntries | TrimEntries */
    case RemoveEmptyEntries_TrimEntries = 3;
}
