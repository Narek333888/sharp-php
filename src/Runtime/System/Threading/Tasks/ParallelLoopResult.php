<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading\Tasks;

/**
 * System.Threading.Tasks.ParallelLoopResult: what Parallel.For and Parallel.ForEach return.
 *
 * @sharp-struct
 */
final class ParallelLoopResult
{
    public bool $IsCompleted = true;
}
