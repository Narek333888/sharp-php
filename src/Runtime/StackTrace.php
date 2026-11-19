<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * Renders uncaught exceptions like .NET, with locations mapped back to .sphp sources:
 *
 *   Unhandled exception. System.InvalidOperationException: Queue empty.
 *      at App.Program.Main() in /app/src/Program.sphp:line 12
 */
final class StackTrace
{
    /**
     * @param array<string, array{source: string, lines: array<int, int>}> $sourceMap absolute PHP file => map
     */
    public function __construct(private readonly array $sourceMap = [])
    {
    }

    public function render(\Throwable $e): string
    {
        $out = 'Unhandled exception. ' . $this->describe($e);
        for ($inner = $e->getPrevious(); $inner !== null; $inner = $inner->getPrevious()) {
            $out .= PHP_EOL . ' ---> ' . $this->describe($inner);
        }

        return $out . PHP_EOL . implode(PHP_EOL, $this->frames($e)) . PHP_EOL;
    }

    private function describe(\Throwable $e): string
    {
        // PHP engine errors such as "Call to a member function Run() on null".
        if ($e instanceof \Error && ErrorHandler::isNullAccess($e->getMessage())) {
            return 'System.NullReferenceException: Object reference not set to an instance of an object.';
        }

        return TypeName::of($e) . ': ' . $e->getMessage();
    }

    /** @return list<string> */
    public function frames(\Throwable $e): array
    {
        $trace = $e->getTrace();
        $frames = [];
        foreach ($trace as $i => $frame) {
            if (($frame['class'] ?? null) === ErrorHandler::class) {
                continue; // the warning handler that turned a PHP warning into this exception
            }
            $file = $i === 0 ? $e->getFile() : ($trace[$i - 1]['file'] ?? null);
            $line = $i === 0 ? $e->getLine() : ($trace[$i - 1]['line'] ?? 0);
            $location = $file !== null ? $this->map($file, $line) : null;
            if ($location === null) {
                continue;
            }
            $frames[] = '   at ' . $this->functionName($frame) . " in {$location[0]}:line {$location[1]}";
        }
        if ($frames === [] && ($location = $this->map($e->getFile(), $e->getLine())) !== null) {
            $frames[] = "   at {$location[0]}:line {$location[1]}";
        }

        return $frames;
    }

    /** @return array{0: string, 1: int}|null */
    public function map(string $file, int $line): ?array
    {
        $entry = $this->sourceMap[$file] ?? null;
        if ($entry === null) {
            return null;
        }
        $sourceLine = $entry['lines'][$line] ?? null;
        if ($sourceLine === null) {
            // Nearest mapped line above.
            for ($l = $line; $l > 0 && $sourceLine === null; $l--) {
                $sourceLine = $entry['lines'][$l] ?? null;
            }
        }

        return [$entry['source'], $sourceLine ?? 0];
    }

    /** @param array<string, mixed> $frame */
    private function functionName(array $frame): string
    {
        $function = is_string($frame['function'] ?? null) ? $frame['function'] : '';
        if (str_contains($function, '{closure')) {
            $function = '<lambda>';
        }
        if (is_string($frame['class'] ?? null)) {
            return TypeName::ofClass($frame['class']) . '.' . $function . '()';
        }

        return $function . '()';
    }
}
