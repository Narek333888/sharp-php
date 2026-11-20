<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Net\Http;

/**
 * System.Net.Http.StringContent: a text request body.
 *
 *   await http.PostAsync(url, new StringContent(json, null, "application/json"));
 */
class StringContent extends HttpContent
{
    /** @param mixed $encoding a charset name ("utf-8"), or null for UTF-8 */
    public function __construct(string $content, mixed $encoding = null, string $mediaType = 'text/plain')
    {
        $charset = is_string($encoding) && $encoding !== '' ? $encoding : 'utf-8';
        parent::__construct($content, str_contains($mediaType, 'charset=') ? $mediaType : "$mediaType; charset=$charset");
    }
}
