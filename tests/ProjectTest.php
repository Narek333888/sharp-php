<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Project;

final class ProjectTest extends TestCase
{
    public function testBuildIsCachedUntilASourceChanges(): void
    {
        $dir = $this->tempDir();
        mkdir("$dir/src");
        file_put_contents("$dir/src/Program.sphp", 'class Program { static void Main() { System.Console.WriteLine(1); } }');
        $project = Project::fromDirectory($dir);

        $first = $project->build();
        $this->assertSame('Program', $first['entry']);
        $this->assertFileExists("$dir/var/sharp/classes/Program.php");
        $manifestTime = filemtime("$dir/var/sharp/manifest.php");

        clearstatcache();
        sleep(1);
        $project->build();
        clearstatcache();
        $this->assertSame($manifestTime, filemtime("$dir/var/sharp/manifest.php"), 'Unchanged sources must not be recompiled');

        file_put_contents("$dir/src/Program.sphp", 'class Program { static void Main() { System.Console.WriteLine(2); } }');
        $project->build();
        clearstatcache();
        $this->assertGreaterThan($manifestTime, filemtime("$dir/var/sharp/manifest.php"));
    }

    public function testConfigurationFromComposerJson(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/composer.json", json_encode(['extra' => ['sharp' => ['source' => 'app', 'output' => 'build/php']]]));
        $project = Project::fromDirectory($dir);

        $this->assertSame(realpath($dir) . '/app', $project->sourceDir);
        $this->assertSame(realpath($dir) . '/build/php', $project->buildDir);
    }

    public function testFailedBuildReportsDiagnostics(): void
    {
        $dir = $this->tempDir();
        mkdir("$dir/src");
        file_put_contents("$dir/src/Program.sphp", 'class Program { static void Main() { nope(); } }');

        $this->expectException(CompilationFailed::class);
        Project::fromDirectory($dir)->build();
    }

    public function testCliRunPassesArgumentsAndExitCode(): void
    {
        $result = $this->runProject(['Program.sphp' => "using System;\nConsole.WriteLine(string.Join(\"+\", args));\nreturn args.Length;"], ['a', 'b', 'c']);

        $this->assertSame("a+b+c\n", $result['stdout']);
        $this->assertSame(3, $result['exit']);
    }

    public function testCliReportsCompileErrors(): void
    {
        $result = $this->runProject(['Program.sphp' => "class P {\n    static void Main() { int x = \"a\" }\n}"]);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString("src/Program.sphp(2,38): error SP1002: ';' expected", $result['stderr']);
        $this->assertStringContainsString('Build FAILED. 1 error(s).', $result['stderr']);
    }
}
