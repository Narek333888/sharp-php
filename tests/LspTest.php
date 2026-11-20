<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Lsp\LanguageService;
use SharpPhp\Lsp\Workspace;

/**
 * The language server: completion, hover, go to definition and diagnostics.
 */
final class LspTest extends TestCase
{
    private const MODELS = <<<'CS'
        namespace App.Models;

        public class Person
        {
            public string Name { get; set; } = "";
            public int Age { get; set; }
            private int secret;
            public string Greet(string greeting) => $"{greeting}, {Name}";
            public static Person Create(string name) => new Person { Name = name };
            public int Peek() => this.secret;
        }
        CS;

    private const PROGRAM = <<<'CS'
        using System;
        using System.Collections.Generic;
        using App.Models;

        namespace App;

        public class Program
        {
            public static void Main(string[] args)
            {
                var person = Person.Create("Ann");
                var names = new List<string> { "a" };
                string title = "x";
                Console.WriteLine(person.Name);
            }
        }
        CS;

    private Workspace $workspace;
    private LanguageService $service;
    private string $program;

    protected function setUp(): void
    {
        $root = $this->tempDir();
        mkdir("$root/src");
        file_put_contents("$root/src/Models.sphp", self::MODELS);
        file_put_contents("$root/src/Program.sphp", self::PROGRAM);
        $this->program = "$root/src/Program.sphp";
        $this->workspace = new Workspace();
        $this->workspace->setRoot($root);
        $this->service = new LanguageService($this->workspace);
    }

    public function testMembersAfterADotInUnfinishedCode(): void
    {
        $this->assertSame(['Age', 'Greet', 'Name', 'Peek', 'Equals', 'GetHashCode', 'GetType', 'ToString'], $this->complete('        var n = person.'), "the class's members (private ones hidden outside it), then object's");
        $this->assertSame(['Create'], $this->complete('        Person.'), 'a type lists its static members');
        $this->assertContains('Where', $this->complete('        Console.WriteLine(names.'), 'LINQ inside an unclosed call');
        $this->assertContains('Add', $this->complete('        Console.WriteLine(names.'));

        $text = $this->complete('        title.To');
        $this->assertContains('ToUpper', $text);
        $this->assertSame(1, count(array_keys($text, 'ToString', true)), 'no duplicates');
        $this->assertContains('Length', $this->complete('        title.'));
        $this->assertContains('WriteLine', $this->complete('        Console.'));
    }

    public function testPrivateMembersAreListedInsideTheirClass(): void
    {
        $models = $this->workspace->text(dirname($this->program) . '/Models.sphp') ?? '';
        $this->workspace->open(dirname($this->program) . '/Models.sphp', str_replace('public int Peek() => this.secret;', 'public int Peek() => this.', $models));

        $items = array_column($this->service->completion(dirname($this->program) . '/Models.sphp', 9, strlen('    public int Peek() => this.')), 'label');
        $this->assertContains('secret', $items);
    }

    public function testNamesInScope(): void
    {
        $items = $this->complete('        per');

        foreach (['args', 'person', 'names', 'title', 'Person', 'Console', 'List', 'var', 'foreach'] as $name) {
            $this->assertContains($name, $items);
        }
    }

    public function testNamespacesAfterUsing(): void
    {
        $this->workspace->open($this->program, str_replace('using System.Collections.Generic;', 'using System.Col', self::PROGRAM));
        $items = array_column($this->service->completion($this->program, 1, strlen('using System.Col')), 'label');

        $this->assertContains('Collections', $items);
        $this->assertContains('Web', $items);
        $this->assertNotContains('Console', $items, 'types are not namespaces');
    }

    public function testHoverAndDefinition(): void
    {
        $hover = $this->service->hover($this->program, 13, 35);
        $this->assertSame("```csharp\n(property) string Person.Name\n```", $hover['contents']['value'] ?? null);

        $hover = $this->service->hover($this->program, 10, 30);
        $this->assertSame("```csharp\n(method) App.Models.Person Person.Create(string name)\n```", $hover['contents']['value'] ?? null);

        $hover = $this->service->hover($this->program, 13, 28);
        $this->assertSame("```csharp\n(local variable) App.Models.Person person\n```", $hover['contents']['value'] ?? null);

        $definition = $this->service->definition($this->program, 13, 35);
        $this->assertStringEndsWith('/src/Models.sphp', (string) ($definition['uri'] ?? ''));
        $this->assertSame(4, $definition['range']['start']['line'] ?? null, 'the line of the Name property');

        $local = $this->service->definition($this->program, 13, 28);
        $this->assertSame(10, $local['range']['start']['line'] ?? null, 'where person is declared');
    }

    public function testDiagnosticsFollowTheOpenDocument(): void
    {
        $this->workspace->open($this->program, str_replace('string title = "x";', 'int title = "x";', self::PROGRAM));
        $diagnostics = $this->service->diagnostics()[Workspace::normalize($this->program)] ?? [];

        $this->assertCount(1, $diagnostics);
        $this->assertSame('SP0029', $diagnostics[0]['code']);
        $this->assertSame(['line' => 12, 'character' => 20], $diagnostics[0]['range']['start']);
        $this->assertSame(['line' => 12, 'character' => 23], $diagnostics[0]['range']['end'], 'the string literal is underlined');

        $this->workspace->open($this->program, self::PROGRAM);
        $this->assertSame([], $this->service->diagnostics()[Workspace::normalize($this->program)] ?? null);
    }

    public function testProtocol(): void
    {
        $root = dirname($this->program, 2);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/sharp', 'lsp'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
        self::assertIsResource($process);
        [$in, $out] = [$pipes[0], $pipes[1]];
        $uri = Workspace::pathToUri(Workspace::normalize($this->program));

        $initialize = $this->request($in, $out, 1, 'initialize', ['rootUri' => Workspace::pathToUri($root), 'capabilities' => new \stdClass()]);
        $this->assertSame(['.'], $initialize['result']['capabilities']['completionProvider']['triggerCharacters']);

        $this->send($in, ['jsonrpc' => '2.0', 'method' => 'initialized', 'params' => new \stdClass()]);
        $this->send($in, ['jsonrpc' => '2.0', 'method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'sharpphp', 'version' => 1, 'text' => str_replace('Console.WriteLine(person.Name);', 'person.', self::PROGRAM)]]]);
        $diagnostics = $this->receive($out, method: 'textDocument/publishDiagnostics');
        $this->assertSame($uri, $diagnostics['params']['uri']);
        $this->assertNotSame([], $diagnostics['params']['diagnostics'], '"person." does not compile');

        $completion = $this->request($in, $out, 2, 'textDocument/completion', ['textDocument' => ['uri' => $uri], 'position' => ['line' => 13, 'character' => 15]]);
        $this->assertContains('Greet', array_column($completion['result']['items'], 'label'));

        $this->request($in, $out, 3, 'shutdown', null);
        $this->send($in, ['jsonrpc' => '2.0', 'method' => 'exit']);
        fclose($in);
        $this->assertSame(0, proc_close($process));
    }

    /** @return list<string> labels of the completion at the end of $line, put in place of line 14 */
    private function complete(string $line): array
    {
        $lines = explode("\n", self::PROGRAM);
        $lines[13] = $line;
        $this->workspace->open($this->program, implode("\n", $lines));

        return array_column($this->service->completion($this->program, 13, strlen($line)), 'label');
    }

    /**
     * @param resource $in
     * @param resource $out
     * @return array<mixed>
     */
    private function request($in, $out, int $id, string $method, mixed $params): array
    {
        $this->send($in, ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        return $this->receive($out, id: $id);
    }

    /**
     * @param resource $in
     * @param array<string, mixed> $message
     */
    private function send($in, array $message): void
    {
        $json = (string) json_encode($message);
        fwrite($in, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
        fflush($in);
    }

    /**
     * @param resource $out
     * @return array<mixed>
     */
    private function receive($out, ?int $id = null, ?string $method = null): array
    {
        for ($i = 0; $i < 50; $i++) {
            $length = 0;
            while (($line = fgets($out)) !== false && trim($line) !== '') {
                if (preg_match('/^Content-Length: (\d+)/i', $line, $m) === 1) {
                    $length = (int) $m[1];
                }
            }
            $body = '';
            while (strlen($body) < $length && !feof($out)) {
                $body .= (string) fread($out, $length - strlen($body));
            }
            $message = json_decode($body, true);
            self::assertIsArray($message, 'the server answered');
            if (($id !== null && ($message['id'] ?? null) === $id) || ($method !== null && ($message['method'] ?? null) === $method)) {
                return $message;
            }
        }
        self::fail('No answer from the language server');
    }
}
