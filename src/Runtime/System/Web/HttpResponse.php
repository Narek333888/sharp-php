<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

use SharpPhp\Runtime\System\ArgumentException;
use SharpPhp\Runtime\System\Collections\Generic\KeyValuePair;

/**
 * System.Web.HttpResponse: status code, headers, cookies and body sent back to the browser.
 * It is immutable: WithHeader(), WithCookie() and WithStatusCode() return a changed copy.
 *
 *   return Results.Html("<h1>Saved</h1>").WithCookie("theme", "dark").WithHeader("X-Saved", "1");
 */
final class HttpResponse implements IResult
{
    public private(set) int $StatusCode;

    public private(set) string $Body;

    /** Response headers (Set-Cookie excluded): Headers["Content-Type"]. */
    public ParameterCollection $Headers {
        get => new ParameterCollection(array_map(static fn (array $h): KeyValuePair => new KeyValuePair($h[0], $h[1]), $this->headers));
    }

    public ?string $ContentType {
        get => $this->Headers['Content-Type'];
    }

    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];

    /** @var list<array{name: string, value: string, options: array{expires?: int, path?: string, secure?: bool, httponly?: bool, samesite?: 'Lax'|'Strict'|'None'}}> */
    private array $cookies = [];

    public function __construct(int $statusCode = 200, string $body = '', ?string $contentType = null)
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new ArgumentException("Invalid HTTP status code: $statusCode");
        }
        $this->StatusCode = $statusCode;
        $this->Body = $body;
        if ($contentType !== null) {
            $this->headers[] = ['Content-Type', $contentType];
        }
    }

    /** A copy with the header set (an existing header with that name is replaced). */
    public function WithHeader(string $name, string $value): self
    {
        if (preg_match('/[\r\n:]/', $name) === 1 || $name === '' || preg_match('/[\r\n]/', $value) === 1) {
            throw new ArgumentException("Invalid header: $name");
        }
        $copy = clone $this;
        $copy->headers = array_values(array_filter($copy->headers, static fn (array $h): bool => strcasecmp($h[0], $name) !== 0));
        $copy->headers[] = [$name, $value];

        return $copy;
    }

    /**
     * response.WithStatusCode(404): a copy with that status code, the body, headers and cookies
     * kept; ArgumentException outside 100 to 599.
     */
    public function WithStatusCode(int $statusCode): self
    {
        $copy = new self($statusCode, $this->Body);
        $copy->headers = $this->headers;
        $copy->cookies = $this->cookies;

        return $copy;
    }

    /**
     * A copy that sets a cookie in the browser. $maxAgeSeconds null: until the browser is closed;
     * 0 or less: delete the cookie.
     */
    public function WithCookie(
        string $name,
        string $value,
        ?int $maxAgeSeconds = null,
        string $path = '/',
        bool $httpOnly = true,
        bool $secure = false,
        string $sameSite = 'Lax',
    ): self {
        if ($name === '' || preg_match('/[=,; \t\r\n]/', $name) === 1) {
            throw new ArgumentException("Invalid cookie name: $name");
        }
        $sameSite = match (strtolower($sameSite)) {
            'lax' => 'Lax',
            'strict' => 'Strict',
            'none' => 'None',
            default => throw new ArgumentException("SameSite must be Lax, Strict or None, not '$sameSite'"),
        };
        $options = ['path' => $path, 'secure' => $secure, 'httponly' => $httpOnly, 'samesite' => $sameSite];
        if ($maxAgeSeconds !== null) {
            $options['expires'] = $maxAgeSeconds > 0 ? time() + $maxAgeSeconds : 1;
        }
        $copy = clone $this;
        $copy->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $copy;
    }

    /** response.ToResponse(), for IResult: the response itself. */
    public function ToResponse(): self
    {
        return $this;
    }

    /**
     * Sends the response through PHP's SAPI.
     *
     * @internal used by WebApp.Run()
     */
    public function Send(bool $includeBody = true): void
    {
        if (!headers_sent()) {
            http_response_code($this->StatusCode);
            header_remove('Content-Type');
            foreach ($this->headers as [$name, $value]) {
                header("$name: $value");
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }
        if ($includeBody) {
            echo $this->Body;
        }
    }
}
