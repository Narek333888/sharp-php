<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.MidpointRounding: how Math.Round treats a value halfway between two (2.5).
 */
enum MidpointRounding: int
{
    /** To the even neighbour: 2.5 => 2, 3.5 => 4 (banker's rounding, the default). */
    case ToEven = 0;
    /** Away from zero: 2.5 => 3, -2.5 => -3. */
    case AwayFromZero = 1;
    /** Towards zero (truncating to the digits): 2.7 => 2. */
    case ToZero = 2;
    /** Down: 2.7 => 2, -2.1 => -3. */
    case ToNegativeInfinity = 3;
    /** Up: 2.1 => 3. */
    case ToPositiveInfinity = 4;
}
