<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * Runs a compiled program: loads its classes and calls the static Main method.
 */
final class Host
{
    /**
     * @param array{entry: ?string, classmap: array<string, string>, sourcemap: array<string, array{source: string, lines: array<int, int>}>} $manifest
     * @param list<string> $args
     */
    public static function run(array $manifest, string $buildDir, array $args = []): int
    {
        Loader::register($manifest['classmap'], $buildDir);

        $entry = $manifest['entry'];
        if ($entry === null) {
            fwrite(STDERR, "error SP5001: Program does not contain a static 'Main' method suitable for an entry point" . PHP_EOL);

            return 1;
        }

        $sourceMap = [];
        foreach ($manifest['sourcemap'] as $file => $map) {
            $sourceMap[$buildDir . DIRECTORY_SEPARATOR . $file] = $map;
        }

        ErrorHandler::register();
        try {
            $main = new \ReflectionMethod($entry, 'Main');
            $result = $main->getNumberOfParameters() > 0 ? $main->invoke(null, $args) : $main->invoke(null);

            return is_int($result) ? $result : 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, (new StackTrace($sourceMap))->render($e));

            return 1;
        } finally {
            ErrorHandler::restore();
        }
    }
}
