<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Compiler\Compiler;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sharp-php-tests-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    /** Compiles a single source and returns the generated PHP. */
    protected function compile(string $source): string
    {
        return (new Compiler())->compileString($source)->files[0]->code;
    }

    /** Compiles and returns the first diagnostic (fails if compilation succeeds). */
    protected function compileError(string $source): \SharpPhp\Compiler\Diagnostic
    {
        try {
            (new Compiler())->compileString($source);
        } catch (CompilationFailed $e) {
            return $e->diagnostics[0];
        }
        self::fail('Expected a compile error');
    }

    /**
     * Writes files into a fresh project directory and runs it with the CLI in a separate process.
     *
     * @param array<string, string> $files path relative to src/ => contents
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int, dir: string}
     */
    protected function runProject(array $files, array $args = []): array
    {
        $dir = $this->tempDir();
        foreach ($files as $path => $contents) {
            $target = "$dir/src/$path";
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, $contents);
        }

        return $this->runCli(['run', '--project=' . $dir, '--', ...$args], $dir);
    }

    /**
     * @param list<string> $arguments
     * @return array{stdout: string, stderr: string, exit: int, dir: string}
     */
    protected function runCli(array $arguments, string $cwd): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/sharp', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exit' => $exit, 'dir' => $cwd];
    }

    protected static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
