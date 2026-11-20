<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Net\Http;

use SharpPhp\Runtime\System\Threading\Tasks\Task;

/**
 * System.Net.Http.HttpContent: the body of a request or a response.
 */
class HttpContent
{
    public function __construct(
        protected string $body = '',
        /** The Content-Type, e.g. "application/json; charset=utf-8", or null. */
        public ?string $ContentType = null,
    ) {
    }

    /**
     * @return Task<mixed>
     * @sharp-return Task<string>
     */
    public function ReadAsStringAsync(): Task
    {
        return Task::FromResult($this->body);
    }

    /** @internal */
    public function bytes(): string
    {
        return $this->body;
    }
}
