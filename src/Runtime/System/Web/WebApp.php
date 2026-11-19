<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Web;

use SharpPhp\Cli\DevServer;
use SharpPhp\Runtime\Host;
use SharpPhp\Runtime\System\InvalidOperationException;

/**
 * System.Web.WebApp: a web application in the style of ASP.NET Core minimal APIs.
 *
 *   var app = WebApp.Create();
 *   app.MapGet("/", req => "Hello from SharpPHP!");
 *   app.MapGet("/hello/{name}", req => $"Hello, {req.Route["name"]}!");
 *   app.MapPost("/users", req => Results.Created("/users/1", new { Id = 1, Name = req.Form["name"] }));
 *   app.Run();
 *
 * A handler gets the HttpRequest and returns the response: a string (sent as text), an IResult
 * (Results.Json(...), Results.NotFound(), ...), nothing (an empty 200), or any other value,
 * which is sent as JSON.
 *
 * Run() answers the current request under a web server (PHP-FPM, Apache, php -S). Started from the
 * command line (php index.php, sharp run, the IDE's Run button) it starts PHP's built-in web server
 * for the project instead, like "dotnet run": open the printed address in a browser.
 */
final class WebApp
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var (\Closure(HttpRequest): mixed)|null */
    private ?\Closure $fallback = null;

    public static function Create(): self
    {
        return new self();
    }

    /** @param \Closure(HttpRequest): mixed $handler */
    public function MapGet(string $pattern, \Closure $handler): self
    {
        return $this->add(['GET'], $pattern, $handler);
    }

    /** @param \Closure(HttpRequest): mixed $handler */
    public function MapPost(string $pattern, \Closure $handler): self
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /** @param \Closure(HttpRequest): mixed $handler */
    public function MapPut(string $pattern, \Closure $handler): self
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    /** @param \Closure(HttpRequest): mixed $handler */
    public function MapPatch(string $pattern, \Closure $handler): self
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    /** @param \Closure(HttpRequest): mixed $handler */
    public function MapDelete(string $pattern, \Closure $handler): self
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    /**
     * A route for every HTTP method.
     *
     * @param \Closure(HttpRequest): mixed $handler
     */
    public function Map(string $pattern, \Closure $handler): self
    {
        return $this->add([], $pattern, $handler);
    }

    /**
     * Answers requests that no route matches (instead of 404 Not Found).
     *
     * @param \Closure(HttpRequest): mixed $handler
     */
    public function MapFallback(\Closure $handler): self
    {
        $this->fallback = $handler;

        return $this;
    }

    /**
     * Under a web server: answers the current request. From the command line: serves the project
     * with PHP's built-in web server at $url (default http://localhost:8000) until Ctrl+C.
     */
    public function Run(?string $url = null): void
    {
        if (!Host::isWebRequest()) {
            $root = Host::projectRoot() ?? throw new InvalidOperationException('WebApp.Run() can start a web server only for a SharpPHP project (SharpPhp\Project::run() or "sharp run").');
            DevServer::serve($root, $url);

            return;
        }

        $request = HttpRequest::FromGlobals();
        // Like ASP.NET Core, Console output of handlers goes to the server's terminal/log, not to the page.
        ob_start();
        try {
            $response = $this->Handle($request);
        } finally {
            $console = (string) ob_get_clean();
            if ($console !== '') {
                Host::log($console);
            }
        }
        $response->Send($request->Method !== 'HEAD');
    }

    /**
     * The response to a request, without sending it: for tests and for calling the app from code.
     *
     *   var response = app.Handle(new HttpRequest("GET", "/hello/Ann"));
     *   Console.WriteLine(response.Body); // Hello, Ann!
     */
    public function Handle(HttpRequest $request): HttpResponse
    {
        $best = null;
        $bestValues = [];
        $allowed = [];
        foreach ($this->routes as $route) {
            $values = $route->match($request->Path);
            if ($values === null) {
                continue;
            }
            if (!$route->allows($request->Method)) {
                array_push($allowed, ...$route->methods);
                continue;
            }
            if ($best === null || self::morePrecise($route, $best)) {
                [$best, $bestValues] = [$route, $values];
            }
        }

        if ($best !== null) {
            return self::toResponse(($best->handler)($request->withRoute($bestValues)));
        }
        if ($this->fallback !== null) {
            return self::toResponse(($this->fallback)($request));
        }
        if ($allowed !== []) {
            return Results::StatusCode(405)->WithHeader('Allow', implode(', ', array_values(array_unique($allowed))));
        }

        return Results::NotFound();
    }

    /** @param list<string> $methods */
    private function add(array $methods, string $pattern, \Closure $handler): self
    {
        $this->routes[] = new Route($methods, $pattern, $handler);

        return $this;
    }

    /** True when $route is more specific than $other (both match the same path); ties keep the first. */
    private static function morePrecise(Route $route, Route $other): bool
    {
        $a = $route->precedence();
        $b = $other->precedence();
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $x = $a[$i] ?? 0;
            $y = $b[$i] ?? 0;
            if ($x !== $y) {
                return $x > $y;
            }
        }

        return false;
    }

    private static function toResponse(mixed $result): HttpResponse
    {
        return match (true) {
            $result instanceof IResult => $result->ToResponse(),
            $result === null => new HttpResponse(200),
            is_string($result) => Results::Text($result),
            default => Results::Json($result),
        };
    }
}
