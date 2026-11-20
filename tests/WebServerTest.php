<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

use SharpPhp\Cli\DevServer;

/**
 * Runs projects under PHP's built-in web server, the way "sharp serve" does, and talks HTTP to them.
 */
final class WebServerTest extends TestCase
{
    /** @var list<resource> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        $this->servers = [];
        parent::tearDown();
    }

    public function testWebAppAnswersRequests(): void
    {
        $url = $this->serve([
            'src/Program.sphp' => <<<'CS'
                using System;
                using System.Web;

                class Program
                {
                    static void Main()
                    {
                        var app = WebApp.Create();
                        app.MapGet("/hello/{name}", req =>
                        {
                            Console.WriteLine("handler ran");
                            return Results.Html($"<p>Hello, {req.Route["name"]}</p>").WithCookie("seen", "1");
                        });
                        app.MapPost("/tags", req => string.Join(",", req.Form.GetValues("tag")));
                        app.MapGet("/boom", req => throw new InvalidOperationException("boom"));
                        app.Run();
                    }
                }
                CS,
            'wwwroot/site.css' => 'body { color: red }',
        ]);

        $hello = $this->request("$url/hello/Ann");
        $this->assertSame(200, $hello['status']);
        $this->assertSame('<p>Hello, Ann</p>', $hello['body'], 'Console output goes to the server log, not to the page');
        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $hello['headers']);
        $this->assertStringContainsString('Set-Cookie: seen=1; path=/; HttpOnly; SameSite=Lax', $hello['headers']);

        $this->assertSame('a,b', $this->request("$url/tags", 'POST', 'tag=a&tag=b')['body']);

        $head = $this->request("$url/hello/Ann", 'HEAD');
        $this->assertSame([200, ''], [$head['status'], $head['body']]);

        $boom = $this->request("$url/boom");
        $this->assertSame(500, $boom['status']);
        $this->assertStringContainsString('System.InvalidOperationException: boom', $boom['body']);
        $this->assertStringContainsString('Program.sphp:line 15', $boom['body']);

        $css = $this->request("$url/site.css");
        $this->assertSame([200, 'body { color: red }'], [$css['status'], $css['body']]);
        $this->assertSame(404, $this->request("$url/src/Program.sphp")['status'], 'only wwwroot/ is public');
        $this->assertSame(404, $this->request("$url/missing")['status']);
    }

    public function testConsoleProgramAndCompileErrorsInTheBrowser(): void
    {
        $url = $this->serve(['src/Program.sphp' => "using System;\nclass P { static void Main() { Console.WriteLine(\"one\"); Console.WriteLine(Console.ReadLine() ?? \"no input\"); } }"]);

        $page = $this->request("$url/");
        $this->assertSame("one\nno input\n", self::lf($page['body']));
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $page['headers']);

        file_put_contents($this->root . '/src/Program.sphp', "class P { static void Main() { int x = \"text\"; } }");
        $error = $this->request("$url/");
        $this->assertSame(500, $error['status']);
        $this->assertStringContainsString('error SP0029: Cannot implicitly convert type &#039;string&#039; to &#039;int&#039;', $error['body']);
    }

    public function testErrorDetailsAreHiddenWhenDisplayErrorsIsOff(): void
    {
        $url = $this->serve(['src/Program.sphp' => "class P { static void Main() { throw new System.InvalidOperationException(\"secret\"); } }"], showErrors: false);

        $error = $this->request("$url/");
        $this->assertSame(500, $error['status']);
        $this->assertStringContainsString('The details are in the server log.', $error['body']);
        $this->assertStringNotContainsString('secret', $error['body']);
    }

    private string $root = '';

    /** @param array<string, string> $files */
    private function serve(array $files, bool $showErrors = true): string
    {
        $this->root = $this->tempDir();
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname("$this->root/$path"))) {
                mkdir(dirname("$this->root/$path"), 0777, true);
            }
            file_put_contents("$this->root/$path", $contents);
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        [$command, $env] = DevServer::command($this->root, '127.0.0.1', $port, $showErrors);
        $server = proc_open($command, [1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']], $pipes, $this->root, $env);
        self::assertIsResource($server);
        $this->servers[] = $server;

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return "http://127.0.0.1:$port";
            }
            usleep(50_000);
        }
        self::fail('The built-in web server did not start');
    }

    /** @return array{status: int, headers: string, body: string} */
    private function request(string $url, string $method = 'GET', ?string $form = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'ignore_errors' => true,
            'header' => $form !== null ? "Content-Type: application/x-www-form-urlencoded\r\n" : '',
            'content' => $form ?? '',
        ]]);
        $body = @file_get_contents($url, false, $context);
        $headers = http_get_last_response_headers() ?? [];
        preg_match('/^HTTP\/\S+ (\d+)/', $headers[0] ?? '', $m);

        return ['status' => (int) ($m[1] ?? 0), 'headers' => implode("\n", $headers), 'body' => $body === false ? '' : $body];
    }
}
