<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Threading\Tasks;

use SharpPhp\Runtime\System\OperationCanceledException;

/**
 * System.Threading.Tasks.TaskCanceledException: a task (Task.Delay(ms, token)) was canceled.
 */
class TaskCanceledException extends OperationCanceledException
{
    protected const ?string DEFAULT_MESSAGE = 'A task was canceled.';
}
