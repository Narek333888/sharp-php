<?php

declare(strict_types=1);

namespace SharpPhp\Cli;

use SharpPhp\Runtime\System\ArgumentException;

/**
 * Serves a SharpPHP project with PHP's built-in web server (for development).
 *
 * Every request runs the project's Main through router.php. Files in the project's wwwroot/
 * directory (CSS, images, scripts) are sent as they are; nothing else of the project (sources,
 * composer.json, the build) is reachable from the browser.
 */
final class DevServer
{
    public const DEFAULT_URL = 'http://localhost:8000';

    /** Runs the server in the foreground until Ctrl+C; returns its exit code. */
    public static function serve(string $projectRoot, ?string $url = null): int
    {
        [$host, $port] = self::parseUrl($url ?? self::DEFAULT_URL);
        [$command, $env] = self::command($projectRoot, $host, $port);
        $address = 'http://' . (str_contains($host, ':') ? "[$host]" : $host) . ":$port";
        fwrite(STDOUT, "SharpPHP: listening on $address (press Ctrl+C to stop)" . PHP_EOL);
        if ($env['SHARP_PUBLIC'] !== '') {
            fwrite(STDOUT, 'Static files: ' . $env['SHARP_PUBLIC'] . PHP_EOL);
        }
        fflush(STDOUT);

        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $projectRoot, $env);
        if (!is_resource($process)) {
            fwrite(STDERR, 'error: cannot start PHP\'s built-in web server' . PHP_EOL);

            return 1;
        }

        return proc_close($process);
    }

    /**
     * The command line and environment of the built-in server for a project.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    public static function command(string $projectRoot, string $host, int $port): array
    {
        $public = $projectRoot . '/wwwroot';
        $hasPublic = is_dir($public);
        $env = getenv();
        $env['SHARP_PROJECT'] = $projectRoot;
        $env['SHARP_AUTOLOAD'] = self::autoloader();
        $env['SHARP_PUBLIC'] = $hasPublic ? $public : '';

        return [[PHP_BINARY, '-S', "$host:$port", '-t', $hasPublic ? $public : $projectRoot, __DIR__ . '/router.php'], $env];
    }

    /** @return array{0: string, 1: int} host and port of "http://localhost:8000", "localhost:8080" or ":9000" */
    public static function parseUrl(string $url): array
    {
        if (preg_match('~^(?:(https?)://)?(\[[0-9a-f:]+\]|[^:/\[\]]*)(?::(\d{1,5}))?/?$~i', trim($url), $m) !== 1) {
            throw new ArgumentException("Invalid server address '$url' (expected something like http://localhost:8000)");
        }
        if (strtolower($m[1]) === 'https') {
            throw new ArgumentException("PHP's built-in web server does not support https; use http://");
        }
        $host = trim($m[2], '[]');
        $port = isset($m[3]) ? (int) $m[3] : 8000;
        if ($port < 1 || $port > 65535) {
            throw new ArgumentException("Invalid port: $port");
        }

        return [$host === '' ? 'localhost' : $host, $port];
    }

    /** The Composer autoloader of the running application, so the server loads the same classes. */
    private static function autoloader(): string
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

        return dirname((string) $loader, 2) . '/autoload.php';
    }
}
