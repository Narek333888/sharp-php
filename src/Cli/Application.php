<?php

declare(strict_types=1);

namespace SharpPhp\Cli;

use SharpPhp\CodeGen\Emitter;
use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Compiler\Compiler;
use SharpPhp\Compiler\SourceFile;
use SharpPhp\Project;
use Throwable;

/**
 * The "sharp" command line tool.
 */
final class Application
{
    private const USAGE = <<<'TXT'
SharpPHP %s - a C#-like language for PHP

Usage:
  sharp run [--project=DIR] [-- args...]   Build (if needed) and run the program's Main method
  sharp serve [--project=DIR] [--port=N] [--host=NAME]
                                           Serve a web app (WebApp) at http://localhost:8000
  sharp build [--project=DIR] [--force]    Compile all .sphp files to PHP
  sharp check [--project=DIR]              Compile without writing anything (for CI)
  sharp compile <file.sphp>                Print the PHP generated for a single file
  sharp clean [--project=DIR]              Delete the build directory
  sharp --version                          Show the version

The project directory defaults to the current directory. Sources and output can be
configured in composer.json: "extra": { "sharp": { "source": "src", "output": "var/sharp" } }

TXT;

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);
        $command = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        try {
            return match ($command) {
                'run' => $this->runCommand($rest),
                'serve' => $this->serveCommand($rest),
                'build' => $this->buildCommand($rest),
                'check' => $this->checkCommand($rest),
                'compile' => $this->compileCommand($rest),
                'clean' => $this->cleanCommand($rest),
                '--version', '-V', 'version' => $this->out('SharpPHP ' . Emitter::VERSION),
                'help', '--help', '-h' => $this->out(sprintf(self::USAGE, Emitter::VERSION)),
                default => $this->fail("Unknown command '$command'. Run 'sharp help' for usage."),
            };
        } catch (CompilationFailed $e) {
            fwrite(STDERR, $e->render(getcwd() ?: null) . PHP_EOL);

            return 1;
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** @param list<string> $args */
    private function runCommand(array $args): int
    {
        $separator = array_search('--', $args, true);
        $programArgs = $separator === false ? [] : array_slice($args, $separator + 1);
        $options = $separator === false ? $args : array_slice($args, 0, $separator);

        return $this->project($options)->run($programArgs);
    }

    /** @param list<string> $args */
    private function serveCommand(array $args): int
    {
        $project = $this->project($args);
        $host = 'localhost';
        $port = '8000';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $port = substr($arg, 7);
            } elseif (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            }
        }
        if (!ctype_digit($port)) {
            return $this->fail("Invalid port '$port'");
        }
        try {
            $project->build();
        } catch (CompilationFailed $e) {
            // Keep serving: the page shows the errors too, and the next request rebuilds.
            fwrite(STDERR, $e->render(getcwd() ?: null) . PHP_EOL . PHP_EOL);
        }

        $host = str_contains($host, ':') ? "[$host]" : $host; // IPv6

        return DevServer::serve($project->root, "http://$host:$port");
    }

    /** @param list<string> $args */
    private function buildCommand(array $args): int
    {
        $project = $this->project($args);
        $started = microtime(true);
        $manifest = $project->build(in_array('--force', $args, true));
        $files = count($manifest['sourcemap']);
        $elapsed = number_format(microtime(true) - $started, 2);

        return $this->out("Build succeeded: $files file(s) -> " . $this->relative($project->buildDir) . " ({$elapsed}s)");
    }

    /** @param list<string> $args */
    private function checkCommand(array $args): int
    {
        $project = $this->project($args);
        $result = (new Compiler())->compileDirectory($project->sourceDir);

        return $this->out('No errors in ' . count($result->files) . ' file(s).');
    }

    /** @param list<string> $args */
    private function compileCommand(array $args): int
    {
        $path = $args[0] ?? null;
        if ($path === null) {
            return $this->fail('Usage: sharp compile <file.sphp>');
        }
        $result = (new Compiler())->compile([SourceFile::fromPath($path)]);
        fwrite(STDOUT, $result->files[0]->code);

        return 0;
    }

    /** @param list<string> $args */
    private function cleanCommand(array $args): int
    {
        $project = $this->project($args);
        $project->clean();

        return $this->out('Removed ' . $this->relative($project->buildDir));
    }

    /** @param list<string> $args */
    private function project(array $args): Project
    {
        $dir = getcwd() ?: '.';
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--project=')) {
                $dir = substr($arg, 10);
            }
        }

        return Project::fromDirectory($dir);
    }

    private function relative(string $path): string
    {
        $cwd = rtrim(getcwd() ?: '', '/') . '/';

        return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
    }

    private function out(string $text): int
    {
        fwrite(STDOUT, rtrim($text) . PHP_EOL);

        return 0;
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, "error: $message" . PHP_EOL);

        return 1;
    }
}
