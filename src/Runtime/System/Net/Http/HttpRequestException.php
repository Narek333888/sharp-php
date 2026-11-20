<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Net\Http;

use SharpPhp\Runtime\System\Exception;

/**
 * System.Net.Http.HttpRequestException: the request failed (network error, or an unsuccessful status
 * for GetStringAsync / EnsureSuccessStatusCode).
 */
class HttpRequestException extends Exception
{
    protected const ?string DEFAULT_MESSAGE = 'An error occurred while sending the request.';

    /** The HTTP status code, when the server answered. */
    public ?int $StatusCode = null;
}
