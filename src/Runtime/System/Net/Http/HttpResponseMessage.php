<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Net\Http;

use SharpPhp\Runtime\System\Web\ParameterCollection;

/**
 * System.Net.Http.HttpResponseMessage: the answer to an HttpClient request.
 */
final class HttpResponseMessage
{
    private const REASONS = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
        409 => 'Conflict', 422 => 'Unprocessable Entity', 429 => 'Too Many Requests', 500 => 'Internal Server Error',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    public bool $IsSuccessStatusCode {
        get => $this->StatusCode >= 200 && $this->StatusCode <= 299;
    }

    public string $ReasonPhrase {
        get => self::REASONS[$this->StatusCode] ?? '';
    }

    /**
     * @param iterable<mixed, mixed> $headers
     */
    public function __construct(
        /** The HTTP status code: 200, 404, ... */
        public readonly int $StatusCode,
        public readonly HttpContent $Content,
        iterable $headers = [],
    ) {
        $this->Headers = new ParameterCollection($headers);
    }

    /** Response headers, case-insensitive: Headers["Content-Type"]. */
    public readonly ParameterCollection $Headers;

    /** Throws HttpRequestException unless the status code is 2xx. */
    public function EnsureSuccessStatusCode(): self
    {
        if (!$this->IsSuccessStatusCode) {
            $e = new HttpRequestException("Response status code does not indicate success: {$this->StatusCode} ({$this->ReasonPhrase}).");
            $e->StatusCode = $this->StatusCode;
            throw $e;
        }

        return $this;
    }
}
