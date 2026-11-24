<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Net\Http;

use SharpPhp\Runtime\EventLoop;
use SharpPhp\Runtime\System\Collections\Generic\KeyValuePair;
use SharpPhp\Runtime\System\NotSupportedException;
use SharpPhp\Runtime\System\Threading\Tasks\Task;

/**
 * System.Net.Http.HttpClient: HTTP requests that run in the background, so several can be in flight:
 *
 *   using var http = new HttpClient();   // or: var http = new HttpClient();
 *   string page = await http.GetStringAsync("https://example.com");
 *   var pages = await Task.WhenAll(urls.Select(u => http.GetStringAsync(u)));
 *
 * Needs PHP's curl extension.
 */
class HttpClient implements \SharpPhp\Runtime\System\IDisposable
{
    /** Prefix for relative request addresses: "https://api.example.com/". */
    public ?string $BaseAddress = null;

    /** Seconds before a request fails. */
    public int $Timeout = 100;

    /** @var array<string, string> headers sent with every request */
    private array $defaultHeaders = [];

    /** Adds a header sent with every request ("Authorization", "Bearer ..."). */
    public function AddDefaultHeader(string $name, string $value): void
    {
        $this->defaultHeaders[$name] = $value;
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<string> the body; fails with HttpRequestException unless the status is 2xx
     */
    public function GetStringAsync(string $requestUri): Task
    {
        return Task::async(function () use ($requestUri): string {
            $response = Task::await($this->GetAsync($requestUri));
            if (!$response instanceof HttpResponseMessage) {
                throw new HttpRequestException();
            }

            return $response->EnsureSuccessStatusCode()->Content->bytes();
        });
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<HttpResponseMessage>
     */
    public function GetAsync(string $requestUri): Task
    {
        return $this->send('GET', $requestUri, null);
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<HttpResponseMessage>
     */
    public function PostAsync(string $requestUri, ?HttpContent $content): Task
    {
        return $this->send('POST', $requestUri, $content);
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<HttpResponseMessage>
     */
    public function PutAsync(string $requestUri, ?HttpContent $content): Task
    {
        return $this->send('PUT', $requestUri, $content);
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<HttpResponseMessage>
     */
    public function DeleteAsync(string $requestUri): Task
    {
        return $this->send('DELETE', $requestUri, null);
    }

    /**
     * http.Dispose() (a using block): nothing to release, as each request opens its own curl
     * handle; the client can still send requests after it.
     */
    public function Dispose(): void
    {
    }

    /**
     * @param non-empty-string $method
     * @return Task<mixed> completed with an HttpResponseMessage
     */
    private function send(string $method, string $uri, ?HttpContent $content): Task
    {
        if (!function_exists('curl_init')) {
            throw new NotSupportedException('HttpClient needs the PHP curl extension (ext-curl).');
        }
        $url = $this->BaseAddress !== null && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $uri)
            ? rtrim($this->BaseAddress, '/') . '/' . ltrim($uri, '/')
            : $uri;
        $headers = [];
        $request = curl_init($url);
        $headerLines = [];
        foreach ($this->defaultHeaders as $name => $value) {
            $headerLines[] = "$name: $value";
        }
        if ($content?->ContentType !== null) {
            $headerLines[] = 'Content-Type: ' . $content->ContentType;
        }
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->Timeout,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[] = new KeyValuePair(trim($name), trim($value));
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $headers = []; // a redirect: keep only the final response's headers
                }

                return strlen($line);
            },
        ]);
        if ($content !== null) {
            curl_setopt($request, CURLOPT_POSTFIELDS, $content->bytes());
        }

        $task = Task::pending();
        EventLoop::request($request, static function (\CurlHandle $handle, int $error) use ($task, &$headers, $method, $url): void {
            if ($error !== 0) {
                $task->fail(new HttpRequestException(curl_strerror($error) . " ($method $url)"));

                return;
            }
            $body = (string) curl_multi_getcontent($handle);
            $type = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            $task->succeed(new HttpResponseMessage(
                (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                new HttpContent($body, is_string($type) ? $type : null),
                $headers,
            ));
        });

        return $task;
    }
}
