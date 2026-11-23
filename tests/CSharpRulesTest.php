<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SharpPhp\Compiler\CompilationFailed;
use SharpPhp\Compiler\Compiler;

/**
 * Programs whose verdict comes from the C# compiler (.NET 10): SharpPHP reports the same error (SP for
 * CS), or compiles them as C# does (tests/Fixtures/csharp-rules.txt).
 */
final class CSharpRulesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rules(): iterable
    {
        $usings = "using System;\nusing System.Collections.Generic;\nusing System.Linq;\nusing System.Threading.Tasks;\n";
        foreach (file(__DIR__ . '/Fixtures/csharp-rules.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$name, $expected, $statements, $declarations] = array_pad(explode("\t", $line), 4, '');
            yield $name => [$expected, $usings . $statements . "\n" . $declarations . "\n"];
        }
    }

    #[DataProvider('rules')]
    public function testVerdictIsTheCSharpCompilers(string $expected, string $source): void
    {
        try {
            (new Compiler())->compileString($source);
            $verdict = 'compiles';
        } catch (CompilationFailed $e) {
            $verdict = $e->diagnostics[0]->code . ': ' . $e->diagnostics[0]->message;
        }

        $this->assertSame($expected, $expected === 'compiles' ? $verdict : substr($verdict, 0, strlen($expected)), $source);
    }
}
