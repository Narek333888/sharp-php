<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.DayOfWeek: DateTime.DayOfWeek, from Sunday (0) to Saturday (6), as in .NET.
 */
enum DayOfWeek: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
}
