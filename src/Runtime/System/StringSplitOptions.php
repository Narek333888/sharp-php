<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.StringSplitOptions for string.Split: text.Split(' ', StringSplitOptions.RemoveEmptyEntries).
 * A flags enum in .NET; combine with |: RemoveEmptyEntries | TrimEntries.
 */
enum StringSplitOptions: int
{
    case None = 0;
    case RemoveEmptyEntries = 1;
    case TrimEntries = 2;
}
