<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading;

/**
 * System.Threading.Timeout: Timeout.Infinite (-1) waits forever (Task.Delay(Timeout.Infinite, token)).
 */
final class Timeout
{
    public const int Infinite = -1;
}
