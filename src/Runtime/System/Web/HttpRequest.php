<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

/**
 * System.Web.HttpRequest: the request a route handler answers.
 *
 *   app.MapGet("/users/{id:int}", req => $"User {req.Route["id"]}, page {req.Query["page"] ?? "1"}");
 *
 * Handlers get it from the web server. Tests can build one and pass it to WebApp.Handle():
 *
 *   var response = app.Handle(new HttpRequest("GET", "/users/7?page=2"));
 */
final class HttpRequest
{
    /** "GET", "POST", ... (upper case). */
    public private(set) string $Method;

    /** The path without the query string, decoded: "/users/7". */
    public private(set) string $Path;

    /** The query string with its "?" ("?page=2"), or "" when there is none. */
    public private(set) string $QueryString;

    /** The query string values: ?page=2 => Query["page"] == "2". */
    public private(set) ParameterCollection $Query;

    /** Fields of a submitted HTML form (application/x-www-form-urlencoded or multipart/form-data). */
    public private(set) ParameterCollection $Form;

    /** Values of the route's {placeholders}: "/users/{id}" and "/users/7" => Route["id"] == "7". */
    public private(set) ParameterCollection $Route;

    /** Request headers, case-insensitive: Headers["User-Agent"]. */
    public private(set) ParameterCollection $Headers;

    /** Cookies sent by the browser. */
    public private(set) ParameterCollection $Cookies;

    /** "http" or "https". */
    public private(set) string $Scheme;

    /** Host name and port the request was sent to: "localhost:8000". */
    public private(set) string $Host;

    /** The raw request body: JSON, text, ... */
    public string $Body {
        get => $this->body ??= ($this->readBody)();
    }

    /** The Content-Type header, or null. */
    public ?string $ContentType {
        get => $this->Headers['Content-Type'];
    }

    /** The full address: "http://localhost:8000/users/7?page=2". */
    public string $Url {
        get => $this->Scheme . '://' . $this->Host . self::encodePath($this->Path) . $this->QueryString;
    }

    private ?string $body = null;

    /** @var \Closure(): string */
    private \Closure $readBody;

    /**
     * @param string $target the path, optionally with a query string: "/search?q=php"
     * @param iterable<mixed, mixed> $form form fields
     * @param iterable<mixed, mixed> $headers header name => value
     * @param iterable<mixed, mixed> $cookies cookie name => value
     */
    public function __construct(
        string $method = 'GET',
        string $target = '/',
        iterable $form = [],
        iterable $headers = [],
        iterable $cookies = [],
        string $body = '',
    ) {
        $this->Method = strtoupper($method);
        $url = 'http://host/' . ltrim($target, '/');
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $this->Path = rawurldecode(is_string($path) && $path !== '' ? $path : '/');
        $this->QueryString = is_string($query) && $query !== '' ? '?' . $query : '';
        $this->Query = ParameterCollection::FromQueryString(is_string($query) ? $query : '');
        $this->Form = new ParameterCollection($form);
        $this->Route = new ParameterCollection();
        $this->Headers = new ParameterCollection($headers);
        $this->Cookies = new ParameterCollection($cookies);
        $this->Scheme = 'http';
        $this->Host = $this->Headers['Host'] ?? 'localhost';
        $this->body = $body;
        $this->readBody = static fn (): string => $body;
    }

    /** The request PHP is answering now ($_SERVER, $_GET, $_POST, $_COOKIE, php://input). */
    public static function FromGlobals(): self
    {
        $server = $_SERVER;
        $request = new self(
            is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/',
            $_POST,
            self::headersFrom($server),
            $_COOKIE,
        );
        // A urlencoded form is read from the body itself, so repeated fields keep all their values
        // (PHP's $_POST keeps only the last one); multipart forms (file uploads) come from $_POST.
        if (str_starts_with(strtolower($request->ContentType ?? ''), 'application/x-www-form-urlencoded')) {
            $request->Form = ParameterCollection::FromQueryString((string) file_get_contents('php://input'));
        }
        $https = $server['HTTPS'] ?? '';
        $request->Scheme = (is_string($https) && $https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
        $request->Host = $request->Headers['Host'] ?? (is_string($server['SERVER_NAME'] ?? null) ? $server['SERVER_NAME'] : 'localhost');
        $request->body = null;
        $request->readBody = static fn (): string => (string) file_get_contents('php://input');

        return $request;
    }

    /**
     * The same request with the values of the route that matched it.
     *
     * @internal used by WebApp
     * @param array<string, string> $values
     */
    public function withRoute(array $values): self
    {
        $copy = clone $this;
        $copy->Route = new ParameterCollection($values);

        return $copy;
    }

    /**
     * @param array<mixed> $server
     * @return array<string, string>
     */
    private static function headersFrom(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = (string) $value;
        }

        return $headers;
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
