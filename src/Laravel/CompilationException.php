<?php

declare(strict_types=1);

namespace SharpPhp\Laravel;

use SharpPhp\Compiler\CompilationFailed;

/**
 * The .sphp sources of a Laravel app do not compile. Laravel shows it on its error page (HTTP 500)
 * with the compiler's messages and code frames. (CompilationFailed itself has a render() method,
 * which Laravel would take for a way to render the exception as the response.)
 */
final class CompilationException extends \RuntimeException
{
    public static function from(CompilationFailed $e, string $basePath): self
    {
        return new self($e->render($basePath), 0, $e);
    }
}
