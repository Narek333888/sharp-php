<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * Runs a compiled program: loads its classes and calls the static Main method.
 *
 * The same program runs from the command line and under a web server (php -S, PHP-FPM, Apache).
 * In a web request there is no terminal: errors become an HTTP 500 page (with details while PHP's
 * display_errors is on, as in development) and are written to the server log.
 */
final class Host
{
    private static ?string $projectRoot = null;

    /**
     * @param array{entry: ?string, classmap: array<string, string>, sourcemap: array<string, array{source: string, lines: array<int, int>}>} $manifest
     * @param list<string> $args
     */
    public static function run(array $manifest, string $buildDir, array $args = [], ?string $projectRoot = null): int
    {
        self::$projectRoot = $projectRoot;
        Loader::register($manifest['classmap'], $buildDir);

        $entry = $manifest['entry'];
        if ($entry === null) {
            self::fail("error SP5001: Program does not contain a static 'Main' method suitable for an entry point");

            return 1;
        }

        $sourceMap = [];
        foreach ($manifest['sourcemap'] as $file => $map) {
            $sourceMap[$buildDir . DIRECTORY_SEPARATOR . $file] = $map;
        }

        if (self::isWebRequest() && !headers_sent()) {
            // Console.WriteLine output reads as lines in a browser; WebApp responses set their own type.
            header('Content-Type: text/plain; charset=utf-8');
        }

        ErrorHandler::register();
        try {
            $main = new \ReflectionMethod($entry, 'Main');
            $result = $main->getNumberOfParameters() > 0 ? $main->invoke(null, $args) : $main->invoke(null);

            return is_int($result) ? $result : 0;
        } catch (\Throwable $e) {
            self::fail((new StackTrace($sourceMap))->render($e));

            return 1;
        } finally {
            ErrorHandler::restore();
        }
    }

    /** True under a web server, false on the command line. */
    public static function isWebRequest(): bool
    {
        return !in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
    }

    /** Root directory of the running project, when it was started through SharpPhp\Project. */
    public static function projectRoot(): ?string
    {
        return self::$projectRoot;
    }

    /** Writes to the terminal of "php -S" / the command line, or to the web server's error log. */
    public static function log(string $text): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || PHP_SAPI === 'phpdbg') {
            file_put_contents('php://stderr', rtrim($text, "\r\n") . PHP_EOL);

            return;
        }
        error_log(rtrim($text));
    }

    /**
     * Reports an error that stops the program (a compile error, an unhandled exception):
     * on stderr from the command line; in a web request, as an HTTP 500 page and in the log.
     */
    public static function fail(string $details): void
    {
        if (!self::isWebRequest()) {
            file_put_contents('php://stderr', rtrim($details, "\r\n") . PHP_EOL);

            return;
        }

        self::log($details);
        $showDetails = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL) || ini_get('display_errors') === 'stderr';
        if (headers_sent()) {
            // Part of the page is already out: the best we can do is append the error to it.
            echo PHP_EOL, $showDetails ? $details : 'Internal Server Error', PHP_EOL;

            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo self::errorPage($showDetails ? $details : null);
    }

    private static function errorPage(?string $details): string
    {
        $body = $details === null
            ? '<p>The server could not complete the request. The details are in the server log.</p>'
            : '<pre>' . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>500 Internal Server Error</title>
            <style>
            body { margin: 0; padding: 32px 16px; font: 15px/1.5 system-ui, sans-serif; background: #fff; color: #1f2328; }
            main { max-width: 960px; margin: 0 auto; }
            h1 { margin: 0 0 16px; font-size: 22px; color: #b42318; }
            pre { margin: 0; padding: 16px; overflow-x: auto; background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 6px; font: 13px/1.45 ui-monospace, Menlo, Consolas, monospace; }
            @media (prefers-color-scheme: dark) {
              body { background: #0d1117; color: #e6edf3; }
              h1 { color: #ff7b72; }
              pre { background: #161b22; border-color: #30363d; }
            }
            </style>
            </head>
            <body>
            <main>
            <h1>500 Internal Server Error</h1>
            $body
            </main>
            </body>
            </html>
            HTML;
    }
}
