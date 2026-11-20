<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SharpPhp\Paths;

/**
 * End-to-end tests: every directory in tests/Fixtures is a small program.
 *
 *   src/*.sphp            sources
 *   expected.txt          expected stdout
 *   expected_stderr.txt   (optional) expected stderr; {src} is replaced by the source directory
 *   args.txt              (optional) program arguments
 *   exit_code.txt         (optional) expected exit code, default 0
 */
final class FixtureTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/Fixtures/*', GLOB_ONLYDIR) ?: [] as $dir) {
            yield basename($dir) => [$dir];
        }
    }

    #[DataProvider('fixtures')]
    public function testFixture(string $fixture): void
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$fixture/src", \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen("$fixture/src/"))] = (string) file_get_contents($file->getPathname());
        }
        $args = is_file("$fixture/args.txt") ? preg_split('/\s+/', trim((string) file_get_contents("$fixture/args.txt"))) : [];

        $result = $this->runProject($files, $args ?: []);

        $expectedExit = is_file("$fixture/exit_code.txt") ? (int) file_get_contents("$fixture/exit_code.txt") : 0;
        $this->assertSame(file_get_contents("$fixture/expected.txt"), $result['stdout'], "stderr:\n" . $result['stderr']);
        $this->assertSame($expectedExit, $result['exit'], "stderr:\n" . $result['stderr']);

        if (is_file("$fixture/expected_stderr.txt")) {
            // Stack traces show the sources as SharpPHP spells paths: with "/" (see Paths).
            $expected = str_replace('{src}', Paths::normalize((string) realpath($result['dir'])) . '/src', (string) file_get_contents("$fixture/expected_stderr.txt"));
            $this->assertSame($expected, $result['stderr']);
        } elseif ($expectedExit === 0) {
            $this->assertSame('', $result['stderr']);
        }
    }
}
