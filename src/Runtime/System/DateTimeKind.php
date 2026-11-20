<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.DateTimeKind: whether a DateTime is a local time (DateTime.Now), a UTC time (DateTime.UtcNow), or
 * neither (new DateTime(2024, 1, 15), most parsed strings).
 */
enum DateTimeKind: int
{
    case Unspecified = 0;
    case Utc = 1;
    case Local = 2;
}
