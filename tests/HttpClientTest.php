<?php

declare(strict_types=1);

namespace SharpPhp\Tests;

/**
 * HttpClient with await: requests in flight at the same time, statuses, errors and request bodies,
 * against PHP's built-in web server (which answers each request after 500 ms).
 */
final class HttpClientTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            // The workers of "php -S" outlive their master's SIGTERM: stop them first (Windows has
            // no workers, see testRequestsRunAtTheSameTime()).
            $status = proc_get_status($this->server);
            if ($status['running'] && PHP_OS_FAMILY !== 'Windows') {
                exec('pkill -TERM -P ' . (int) $status['pid']);
            }
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testRequestsRunAtTheSameTime(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('HttpClient needs ext-curl');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            // The test server answers requests in parallel only with PHP_CLI_SERVER_WORKERS, which PHP's
            // built-in server does not support on Windows, so the requests could not overlap there.
            self::markTestSkipped("PHP's built-in web server answers one request at a time on Windows");
        }
        $url = $this->startServer();

        $result = $this->runProject(['Program.sphp' => <<<CS
            using System;
            using System.Linq;

            var http = new HttpClient { BaseAddress = "$url" };
            await http.GetStringAsync("/warm-up");

            // One after another would take at least 1500 ms; at the same time about 500 ms.
            var started = Environment.TickCount;
            var pages = await Task.WhenAll(new[] { "/a", "/b", "/c" }.Select(u => http.GetStringAsync(u)));
            var elapsed = Environment.TickCount - started;
            Console.WriteLine(string.Join(" | ", pages));
            Console.WriteLine(elapsed < 1200 ? "at the same time" : \$"one after another ({elapsed} ms)");

            var missing = await http.GetAsync("/missing");
            Console.WriteLine(\$"{missing.StatusCode} {missing.ReasonPhrase} {missing.IsSuccessStatusCode}");
            try
            {
                await http.GetStringAsync("/missing");
            }
            catch (HttpRequestException e)
            {
                Console.WriteLine(e.Message + " " + e.StatusCode);
            }

            var posted = await http.PostAsync("/save", new StringContent("{\\"x\\":1}", null, "application/json"));
            Console.WriteLine(await posted.Content.ReadAsStringAsync());
            Console.WriteLine(posted.Headers["content-type"]);
            CS]);

        $this->assertSame(<<<'TXT'
            page /a | page /b | page /c
            at the same time
            404 Not Found False
            Response status code does not indicate success: 404 (Not Found). 404
            {"got":"{\"x\":1}","type":"application/json; charset=utf-8"}
            application/json

            TXT, $result['stdout'], $result['stderr']);
    }

    private function startServer(): string
    {
        $root = $this->tempDir();
        file_put_contents("$root/router.php", <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path !== '/warm-up') { usleep(500000); }
            if ($path === '/missing') { http_response_code(404); echo 'nope'; return; }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                header('Content-Type: application/json');
                echo json_encode(['got' => file_get_contents('php://input'), 'type' => $_SERVER['CONTENT_TYPE'] ?? ''], JSON_UNESCAPED_SLASHES);
                return;
            }
            echo "page $path";
            PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $env = getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = '8'; // answer requests in parallel
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$root/router.php"], [1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']], $pipes, $root, $env);
        self::assertIsResource($server);
        $this->server = $server;
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return "http://127.0.0.1:$port";
            }
            usleep(50_000);
        }
        self::fail('The test server did not start');
    }
}
